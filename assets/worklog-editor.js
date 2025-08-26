/**
 * 作業ログ促し（固定通知）エディタ統合JavaScript
 * Gutenberg エディタで保存後に作業ログ記録を促す上部固定通知を表示
 */
(function() {
    'use strict';
    
    // デバッグログのON/OFF制御
    const DEBUG_WORKLOG = true;
    
    function debugLog(type, message, data = {}) {
        if (DEBUG_WORKLOG) {
            console.debug(`[worklog][${type}]`, message, data);
        }
    }
    
    // WordPress の必要なコンポーネントを取得
    const { select, subscribe, dispatch } = wp.data;
    
    // 状態管理
    let isInitialized = false;
    let subscriptionHandle = null;
    let prevState = null;
    let currentPostId = null;
    let worklogNoticeId = 'ofwn-worklog-notice';
    
    // サイクル管理（1サイクル=1回表示）
    let saveCycleId = 0;
    let shownInThisCycle = false;
    let wasDirtyBeforeSave = false;
    let lastNoticeAt = 0; // デバウンス用
    
    /**
     * 単一購読管理（二重登録防止）
     */
    function ensureSingleSubscribe() {
        if (subscriptionHandle) {
            debugLog('sub', 'subscription already exists, skipping');
            return;
        }
        
        debugLog('sub', 'setting up single subscription');
        subscriptionHandle = subscribe(saveStateMonitor);
    }
    
    /**
     * 保存状態監視（メインハンドラー）
     */
    function saveStateMonitor() {
        try {
            const editorSel = select('core/editor');
            if (!editorSel) return;
            
            const currentState = {
                isSaving: editorSel.isSavingPost(),
                isAutosaving: editorSel.isAutosavingPost(),
                savedOk: editorSel.didPostSaveRequestSucceed(),
                isDirty: editorSel.isEditedPostDirty()
            };
            
            // 保存開始検知（false→true遷移）で新サイクル開始
            if (prevState && !prevState.isSaving && currentState.isSaving) {
                saveCycleId++;
                shownInThisCycle = false;
                wasDirtyBeforeSave = prevState.isDirty; // 保存開始時のDirty状態を記録
                
                debugLog('cycle', 'save start', {
                    saveCycleId,
                    wasDirtyBeforeSave,
                    isAutosaving: currentState.isAutosaving
                });
            }
            
            // 保存完了検知（エッジ検出）
            if (prevState && 
                prevState.isSaving && 
                !currentState.isSaving && 
                !currentState.isAutosaving && 
                currentState.savedOk) {
                
                debugLog('edge', 'save completed successfully', {
                    saveCycleId,
                    wasDirtyBeforeSave,
                    shownInThisCycle
                });
                
                // 通知表示判定
                checkAndShowNotice();
            }
            
            prevState = currentState;
            
        } catch (error) {
            console.error('[worklog] Error in save state monitor:', error);
        }
    }
    
    /**
     * 通知表示判定とフィルタリング
     */
    function checkAndShowNotice() {
        const now = Date.now();
        
        // フェイルセーフ: オートセーブは除外
        if (prevState && prevState.isAutosaving) {
            debugLog('skip', 'autosave cycle', {reason: 'autosave'});
            return;
        }
        
        // フェイルセーフ: 変更なし保存は除外
        if (!wasDirtyBeforeSave) {
            debugLog('skip', 'no changes before save', {reason: 'not dirty'});
            return;
        }
        
        // フェイルセーフ: 既に同サイクルで表示済み
        if (shownInThisCycle) {
            debugLog('skip', 'already shown in this cycle', {
                reason: 'same cycle',
                saveCycleId
            });
            return;
        }
        
        // フェイルセーフ: デバウンス（3秒以内の連続は無視）
        const timeSinceLastNotice = now - lastNoticeAt;
        if (timeSinceLastNotice < 3000) {
            debugLog('skip', 'debounce period', {
                reason: 'debounce',
                timeSinceLastNotice: Math.round(timeSinceLastNotice / 1000)
            });
            return;
        }
        
        // 強制表示フック（開発用）
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('worklog_test') === '1') {
            debugLog('force', 'test mode activated');
            // クエリパラメータを削除（多重表示防止）
            urlParams.delete('worklog_test');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            history.replaceState({}, '', newUrl);
            
            showWorklogNotice();
            return;
        }
        
        // worklog_status の確認（非ブロッキング）
        checkWorklogStatusAndShow();
    }
    
    /**
     * worklog_status確認と通知表示
     */
    async function checkWorklogStatusAndShow() {
        if (!currentPostId) {
            debugLog('skip', 'no currentPostId');
            return;
        }
        
        let shouldShow = true; // デフォルトで表示（非ブロッキング）
        
        try {
            // 現在の作業ログ状態を取得（空でも続行）
            const response = await wp.apiFetch({
                path: `/wp/v2/posts/${currentPostId}?_fields=worklog_status`,
                method: 'GET'
            });
            
            const worklogStatus = response.worklog_status;
            
            if (worklogStatus) {
                debugLog('api', 'worklog_status received', {worklogStatus});
                shouldShow = worklogStatus.should_prompt !== false;
            } else {
                debugLog('warn', 'worklog_status empty, proceeding with default', {response});
                // 空レス時もデフォルトで表示
            }
            
        } catch (error) {
            debugLog('warn', 'API error, proceeding with default', {error});
            console.warn('[worklog] worklog_status取得エラー（続行）:', error);
            // APIエラー時もデフォルトで表示
        }
        
        if (shouldShow) {
            showWorklogNotice();
        } else {
            debugLog('skip', 'should_prompt is false', {reason: 'server blocked'});
        }
    }
    
    /**
     * 作業ログ固定通知を表示
     */
    function showWorklogNotice() {
        try {
            const { createNotice, removeNotice } = dispatch('core/notices');
            
            // 既存の通知を削除
            removeNotice(worklogNoticeId);
            
            // 固定通知（上部）を作成
            createNotice('default', '作業メモを残しますか？', {
                id: worklogNoticeId,
                isDismissible: true,
                actions: [
                    {
                        label: '今すぐ書く',
                        onClick: () => {
                            debugLog('click', 'write now button clicked');
                            removeNotice(worklogNoticeId);
                            openWorklogSidebar();
                        }
                    },
                    {
                        label: '今回はスルー',
                        onClick: () => {
                            debugLog('click', 'skip button clicked');
                            removeNotice(worklogNoticeId);
                            // スキップ処理（必要に応じてサーバー通知）
                        }
                    }
                ]
            });
            
            // 表示完了の記録
            shownInThisCycle = true;
            lastNoticeAt = Date.now();
            
            debugLog('show', 'fixed notice created', {
                id: worklogNoticeId,
                saveCycleId,
                timestamp: lastNoticeAt
            });
            
        } catch (error) {
            console.error('[worklog] Error creating notice:', error);
        }
    }
    
    /**
     * 作業ログサイドバーを開く
     */
    function openWorklogSidebar() {
        try {
            const { openGeneralSidebar } = dispatch('core/edit-post');
            
            // サイドバーを開く（プラグイン固有のサイドバーID）
            openGeneralSidebar('ofwn/worklog-sidebar');
            
            debugLog('sidebar', 'worklog sidebar opened');
            
        } catch (error) {
            console.warn('[worklog] サイドバーオープンに失敗、代替処理へ:', error);
            
            // 代替処理：メタボックスへスクロール
            scrollToWorklogMetabox();
        }
    }
    
    /**
     * 作業ログメタボックスへスクロール（代替処理）
     */
    function scrollToWorklogMetabox() {
        const selectors = [
            '#ofwn_parent .ofwn-quick-content',
            '#ofwn_fields',
            '.ofwn-worklog-input',
            'textarea[name="ofwn_quick_content"]'
        ];
        
        let targetElement = null;
        
        for (const selector of selectors) {
            targetElement = document.querySelector(selector);
            if (targetElement) break;
        }
        
        if (targetElement) {
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
            
            debugLog('scroll', 'scrolled to worklog metabox');
        } else {
            debugLog('warn', 'worklog input not found');
        }
    }
    
    /**
     * 初期化処理
     */
    function initWorklogNotice() {
        if (isInitialized) {
            debugLog('init', 'already initialized, skipping');
            return;
        }
        
        debugLog('init', 'initializing worklog notice system');
        
        // エディタが読み込まれるまで待機
        const initSub = subscribe(() => {
            try {
                const editorSel = select('core/editor');
                if (!editorSel) return;
                
                const postId = editorSel.getCurrentPostId();
                const postType = editorSel.getCurrentPostType();
                
                if (postId && postType) {
                    currentPostId = postId;
                    isInitialized = true;
                    
                    // 初期化完了後、購読を解除して本監視を開始
                    if (initSub) {
                        initSub();
                    }
                    
                    debugLog('init', 'initialized for post', {
                        postId,
                        postType
                    });
                    
                    // メイン監視開始
                    ensureSingleSubscribe();
                }
            } catch (error) {
                console.error('[worklog] Error in init subscribe:', error);
            }
        });
    }
    
    // 現在状態を覗くAPI（デバッグ用）
    window.worklogDebugState = () => {
        const editorSel = select('core/editor');
        return {
            // エディタ状態
            saving: editorSel ? editorSel.isSavingPost() : null,
            autosaving: editorSel ? editorSel.isAutosavingPost() : null,
            savedOk: editorSel ? editorSel.didPostSaveRequestSucceed() : null,
            isDirty: editorSel ? editorSel.isEditedPostDirty() : null,
            postId: editorSel ? editorSel.getCurrentPostId() : null,
            postType: editorSel ? editorSel.getEditedPostAttribute('type') : null,
            
            // 内部状態
            isInitialized,
            hasSubscription: !!subscriptionHandle,
            currentPostId,
            
            // サイクル管理
            saveCycleId,
            shownInThisCycle,
            wasDirtyBeforeSave,
            lastNoticeAt,
            debounceRemain: Math.max(0, Math.ceil((lastNoticeAt + 3000 - Date.now()) / 1000)),
            
            // 前回状態
            prevState
        };
    };
    
    // DOM読み込み完了時に初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWorklogNotice);
    } else {
        initWorklogNotice();
    }
    
    // エディタが遅れて読み込まれる場合の保険
    setTimeout(initWorklogNotice, 1000);
    
    // クリーンアップ
    window.addEventListener('beforeunload', () => {
        if (subscriptionHandle) {
            debugLog('cleanup', 'unsubscribing on beforeunload');
            subscriptionHandle();
        }
    });
    
})();