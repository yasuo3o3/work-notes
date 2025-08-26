/**
 * 作業ログ促し（Snackbar）エディタ統合JavaScript
 * Gutenberg エディタで保存後に作業ログ記録を促すSnackbarを表示
 */
(function() {
    'use strict';
    
    // デバッグログのON/OFF制御
    const DEBUG_WORKLOG = true;
    
    function debugLog(...args) {
        if (DEBUG_WORKLOG) {
            console.debug('[worklog]', ...args);
        }
    }
    
    // WordPress の必要なコンポーネントを取得
    const { select, subscribe, dispatch } = wp.data;
    const { __ } = wp.i18n;
    const { createNotice, removeNotice } = dispatch('core/notices');
    
    // 状態管理
    let isInitialized = false;
    let subscriptionHandle = null;
    let lastSaveState = null;
    let currentPostId = null;
    let worklogNoticeId = 'ofwn-worklog-prompt';
    
    // 再入防止・多重実行防止フラグ
    let handlerRunning = false;
    let showingNotice = false;
    let lastHandledAt = 0; // 同一保存サイクルでの多重実行防止
    
    /**
     * subscribe単一登録管理
     */
    function ensureSubscribed() {
        if (subscriptionHandle) {
            debugLog('subscription already exists, skipping');
            return;
        }
        
        debugLog('setting up save monitoring subscription');
        subscriptionHandle = subscribe(saveStateHandler);
    }
    
    /**
     * 保存状態監視ハンドラー（再入防止付き）
     */
    function saveStateHandler() {
        if (handlerRunning) {
            if (DEBUG_WORKLOG) console.log('[worklog][skip]', 'F1', {reason: 'handler already running'});
            return;
        }
        
        handlerRunning = true;
        let triggerDetected = false;
        
        try {
            const coreSel = wp.data.select('core');
            const editorSel = wp.data.select('core/editor');
            
            if (!editorSel) {
                if (DEBUG_WORKLOG) console.log('[worklog][skip]', 'E1', {reason: 'no editor selector'});
                return;
            }
            
            const postType = editorSel.getCurrentPostType();
            const hasEdits = 
                coreSel && typeof coreSel.hasEditsForEntityRecord === 'function'
                    ? coreSel.hasEditsForEntityRecord('postType', postType, currentPostId)
                    : (editorSel.isEditedPostDirty && editorSel.isEditedPostDirty());
            
            const currentSaveState = {
                isSaving: editorSel.isSavingPost(),
                isAutosaving: editorSel.isAutosavingPost(),
                savedOk: editorSel.didPostSaveRequestSucceed(),
                hasEdits: hasEdits
            };
            
            // オートセーブ中の場合はスキップ
            if (currentSaveState.isAutosaving) {
                if (DEBUG_WORKLOG) console.log('[worklog][skip]', 'A1', {reason: 'autosaving', currentSaveState});
                lastSaveState = currentSaveState;
                return;
            }
            
            // 保存完了エッジ検知（ワンショット・デバウンス付き）
            if (lastSaveState && 
                !lastSaveState.isAutosaving && 
                !currentSaveState.isAutosaving) {
                
                const now = Date.now();
                const timeSinceLastHandle = now - lastHandledAt;
                
                // 3秒以内の再発火は無視
                if (timeSinceLastHandle < 3000) {
                    // デバウンス中はS1も出さない（静寂を保つ）
                    lastSaveState = currentSaveState;
                    return;
                }
                
                // 条件A: prev.isSaving → curr.isSaving の変化 + curr.savedOk
                const conditionA = lastSaveState.isSaving && 
                                   !currentSaveState.isSaving && 
                                   currentSaveState.savedOk;
                
                // 条件B: prev.savedOk → curr.savedOk の変化（高速環境対応）
                const conditionB = !lastSaveState.savedOk && 
                                   currentSaveState.savedOk;
                
                if (conditionA || conditionB) {
                    triggerDetected = true;
                    lastHandledAt = now;
                    
                    if (DEBUG_WORKLOG) {
                        console.log('[worklog][trigger]', 'edge', {
                            prev: lastSaveState,
                            curr: currentSaveState,
                            conditionA,
                            conditionB,
                            epoch: now
                        });
                    }
                    
                    // 次tickで通知判定を呼ぶ（ワンショット）
                    setTimeout(() => {
                        checkAndShowWorklogPrompt();
                    }, 0);
                }
            }
            
            // トリガ未成立時のS1ログ（1回だけ、連発しない）
            if (!triggerDetected && lastSaveState && DEBUG_WORKLOG) {
                // 前回の状態更新から十分時間が経っていて、まだ保存完了していない場合のみ
                const timeSinceLastState = Date.now() - (lastSaveState._timestamp || 0);
                if (timeSinceLastState > 1000) { // 1秒経過後にS1を1回だけ
                    console.log('[worklog][skip]', 'S1', {
                        reason: 'not save completion edge',
                        lastSaveState,
                        currentSaveState
                    });
                }
            }
            
            // 状態更新にタイムスタンプを追加
            currentSaveState._timestamp = Date.now();
            lastSaveState = currentSaveState;
            
        } catch (error) {
            console.error('[worklog] Error in save state handler:', error);
        } finally {
            handlerRunning = false;
        }
    }
    
    /**
     * 作業ログ促しの表示判定とSnackbar表示
     */
    async function checkAndShowWorklogPrompt() {
        if (!currentPostId) {
            if (DEBUG_WORKLOG) console.log('[worklog][skip]', 'P0', {reason: 'no currentPostId'});
            return;
        }
        
        // 強制表示フック（開発用）
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('worklog_test') === '1') {
            if (DEBUG_WORKLOG) console.log('[worklog][force]', 'test mode activated');
            // クエリパラメータを削除（多重表示防止）
            urlParams.delete('worklog_test');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            history.replaceState({}, '', newUrl);
            
            showWorklogPrompt({test_mode: true});
            return;
        }
        
        try {
            // 現在の作業ログ状態を取得
            const response = await wp.apiFetch({
                path: `/wp/v2/posts/${currentPostId}?_fields=worklog_status`,
                method: 'GET'
            });
            
            const worklogStatus = response.worklog_status;
            
            if (worklogStatus && worklogStatus.should_prompt) {
                debugLog('conditions met, showing worklog prompt');
                showWorklogPrompt(worklogStatus);
            } else {
                // 詳細な理由トレース
                if (!worklogStatus) {
                    if (DEBUG_WORKLOG) console.log('[worklog][skip]', 'S0', {reason: 'no worklog_status in response', response});
                } else if (!worklogStatus.should_prompt) {
                    if (DEBUG_WORKLOG) console.log('[worklog][skip]', 'C0', {reason: 'should_prompt is false', worklogStatus});
                }
            }
        } catch (error) {
            if (DEBUG_WORKLOG) console.log('[worklog][skip]', 'E0', {reason: 'API error', error});
            console.error('[worklog] 作業ログ状態の取得に失敗:', error);
        }
    }
    
    /**
     * 作業ログ促しSnackbarを表示（多重発火防止）
     */
    function showWorklogPrompt(worklogStatus) {
        if (showingNotice) {
            if (DEBUG_WORKLOG) console.log('[worklog][skip]', 'F1', {reason: 'notice already showing'});
            return;
        }
        
        showingNotice = true;
        if (DEBUG_WORKLOG) console.log('[worklog][show]', 'preparing to show notice', {worklogStatus});
        
        // 次tickで実行（同期実行防止）
        setTimeout(() => {
            try {
                // 既存の通知を削除
                removeNotice(worklogNoticeId);
                
                // カスタマイズ可能なメッセージ（テストモード対応）
                const message = worklogStatus.test_mode 
                    ? 'TEST: 作業ログを残しますか？（強制表示テスト）'
                    : (ofwnWorklogEditor.strings.prompt_message || '今回の変更の作業ログを残しますか？');
                
                // Snackbar（通知）を表示
                createNotice('info', message, {
                    id: worklogNoticeId,
                    isDismissible: true,
                    actions: [
                        {
                            label: ofwnWorklogEditor.strings.write_now || '今すぐ書く',
                            onClick: () => {
                                removeNotice(worklogNoticeId);
                                showingNotice = false;
                                scrollToWorklogInput();
                            }
                        },
                        {
                            label: ofwnWorklogEditor.strings.skip_this_time || '今回はスルー',
                            onClick: () => {
                                removeNotice(worklogNoticeId);
                                showingNotice = false;
                                skipWorklog();
                            }
                        }
                    ]
                });
                
                if (DEBUG_WORKLOG) console.log('[worklog][show]', 'notice created successfully', {id: worklogNoticeId});
                
                // 自動消滅タイマー（設定可能）
                const autoHideDelay = ofwnWorklogEditor.autoHideDelay || 10000; // 10秒
                setTimeout(() => {
                    removeNotice(worklogNoticeId);
                    showingNotice = false;
                    if (DEBUG_WORKLOG) console.log('[worklog][hide]', 'notice auto-hidden');
                }, autoHideDelay);
                
            } catch (error) {
                console.error('[worklog] Error showing notice:', error);
            } finally {
                // エラーが起きてもフラグを確実に戻す
                if (showingNotice) {
                    showingNotice = false;
                }
            }
        }, 0);
    }
    
    /**
     * 作業メモ入力欄へスクロール
     */
    function scrollToWorklogInput() {
        // 作業メモ入力欄を探す
        const worklogSelectors = [
            '#ofwn_parent .ofwn-quick-content', // 親投稿の作業メモ入力欄
            '#ofwn_fields', // 作業メモ属性メタボックス
            '.ofwn-worklog-input', // カスタムクラス
            'textarea[name="ofwn_quick_content"]' // 作業メモテキストエリア
        ];
        
        let targetElement = null;
        
        for (const selector of worklogSelectors) {
            targetElement = document.querySelector(selector);
            if (targetElement) break;
        }
        
        if (targetElement) {
            // スクロールしてフォーカス
            targetElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'center' 
            });
            
            // 入力欄にフォーカス
            const inputElement = targetElement.tagName === 'TEXTAREA' ? 
                targetElement : 
                targetElement.querySelector('textarea, input[type="text"]');
            
            if (inputElement) {
                setTimeout(() => {
                    inputElement.focus();
                }, 300);
            }
        } else {
            // 作業メモ入力欄が見つからない場合の代替処理
            showFallbackWorklogDialog();
        }
    }
    
    /**
     * 作業ログをスキップ
     */
    async function skipWorklog() {
        if (!currentPostId) return;
        
        try {
            const formData = new FormData();
            formData.append('action', 'ofwn_skip_worklog');
            formData.append('nonce', ofwnWorklogEditor.nonce);
            formData.append('post_id', currentPostId);
            
            const response = await fetch(ofwnWorklogEditor.ajax_url, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                // スキップ成功の通知（控えめに）
                createNotice('info', result.data.message || 'スキップしました', {
                    isDismissible: true,
                    type: 'snackbar'
                });
            } else {
                console.error('[worklog] スキップに失敗:', result.data.message);
            }
        } catch (error) {
            console.error('[worklog] スキップ処理でエラー:', error);
        }
    }
    
    /**
     * 代替の作業ログ入力ダイアログを表示
     */
    function showFallbackWorklogDialog() {
        const logText = prompt(
            ofwnWorklogEditor.strings.fallback_prompt || 
            '作業ログを入力してください（空の場合はスキップされます）:'
        );
        
        if (logText && logText.trim()) {
            saveWorklogDirect(logText.trim());
        } else {
            // 空の場合はスキップ
            skipWorklog();
        }
    }
    
    /**
     * 作業ログを直接保存
     */
    async function saveWorklogDirect(logText) {
        if (!currentPostId || !logText) return;
        
        try {
            const worklogStatus = await getCurrentWorklogStatus();
            
            const formData = new FormData();
            formData.append('action', 'ofwn_save_worklog');
            formData.append('nonce', ofwnWorklogEditor.nonce);
            formData.append('post_id', currentPostId);
            formData.append('log_text', logText);
            formData.append('revision_id', worklogStatus.current_revision);
            
            const response = await fetch(ofwnWorklogEditor.ajax_url, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                createNotice('success', result.data.message || '作業ログを保存しました', {
                    isDismissible: true,
                    type: 'snackbar'
                });
            } else {
                createNotice('error', result.data.message || '作業ログの保存に失敗しました', {
                    isDismissible: true
                });
            }
        } catch (error) {
            console.error('[worklog] 作業ログ保存でエラー:', error);
            createNotice('error', '作業ログの保存中にエラーが発生しました', {
                isDismissible: true
            });
        }
    }
    
    /**
     * 現在の作業ログ状態を取得
     */
    async function getCurrentWorklogStatus() {
        const formData = new FormData();
        formData.append('action', 'ofwn_get_worklog_status');
        formData.append('nonce', ofwnWorklogEditor.nonce);
        formData.append('post_id', currentPostId);
        
        const response = await fetch(ofwnWorklogEditor.ajax_url, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            return result.data;
        } else {
            throw new Error(result.data.message || '状態取得に失敗');
        }
    }
    
    /**
     * 初期化処理
     */
    function initWorklogPrompt() {
        if (isInitialized) {
            debugLog('already initialized, skipping');
            return;
        }
        
        debugLog('initializing worklog prompt');
        
        // エディタが読み込まれるまで待機
        const unsubscribe = subscribe(() => {
            const postId = select('core/editor').getCurrentPostId();
            const postType = select('core/editor').getCurrentPostType();
            
            if (postId && postType) {
                currentPostId = postId;
                isInitialized = true;
                unsubscribe();
                
                debugLog('initialized for post:', postId, 'type:', postType);
                
                // 保存状態を監視
                ensureSubscribed();
            }
        });
    }
    
    // DOM読み込み完了時に初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWorklogPrompt);
    } else {
        initWorklogPrompt();
    }
    
    // エディタが遅れて読み込まれる場合に備えて
    setTimeout(initWorklogPrompt, 1000);
    
    // 現在状態を覗く API（読み取り専用）
    window.worklogDebugState = () => {
        const editorSel = select('core/editor');
        return {
            saving: editorSel ? editorSel.isSavingPost() : null,
            autosaving: editorSel ? editorSel.isAutosavingPost() : null,
            savedOk: editorSel ? editorSel.didPostSaveRequestSucceed() : null,
            postId: editorSel ? editorSel.getCurrentPostId() : null,
            postType: editorSel ? editorSel.getEditedPostAttribute('type') : null,
            isShowingNotice: showingNotice,
            isHandlerRunning: handlerRunning,
            isInitialized: isInitialized,
            hasSubscription: !!subscriptionHandle,
            lastSaveState: lastSaveState,
            currentPostId: currentPostId
        };
    };
    
})();