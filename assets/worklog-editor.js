/**
 * 作業ログ促し（Snackbar）エディタ統合JavaScript
 * Gutenberg エディタで保存後に作業ログ記録を促すSnackbarを表示
 */
(function() {
    'use strict';
    
    // WordPress の必要なコンポーネントを取得
    const { select, subscribe, dispatch } = wp.data;
    const { __ } = wp.i18n;
    const { createNotice, removeNotice } = dispatch('core/notices');
    
    // 状態管理
    let isInitialized = false;
    let lastSaveState = null;
    let currentPostId = null;
    let worklogNoticeId = 'ofwn-worklog-prompt';
    
    /**
     * 初期化処理
     */
    function initWorklogPrompt() {
        if (isInitialized) return;
        
        // エディタが読み込まれるまで待機
        const unsubscribe = subscribe(() => {
            const postId = select('core/editor').getCurrentPostId();
            const postType = select('core/editor').getCurrentPostType();
            
            if (postId && postType) {
                currentPostId = postId;
                isInitialized = true;
                unsubscribe();
                
                // 保存状態を監視
                startSaveMonitoring();
            }
        });
    }
    
    /**
     * 保存状態の監視を開始
     */
    function startSaveMonitoring() {
        subscribe(() => {
            const currentSaveState = {
                isSaving: select('core/editor').isSavingPost(),
                isAutosaving: select('core/editor').isAutosavingPost(),
                hasEdits: select('core/editor').hasEditsForEntityRecord('postType', select('core/editor').getCurrentPostType(), currentPostId)
            };
            
            // 保存完了を検知
            if (lastSaveState && 
                lastSaveState.isSaving && 
                !currentSaveState.isSaving && 
                !currentSaveState.isAutosaving) {
                
                // 保存完了後に作業ログ促しを検討
                setTimeout(() => {
                    checkAndShowWorklogPrompt();
                }, 500); // 少し待ってからチェック
            }
            
            lastSaveState = currentSaveState;
        });
    }
    
    /**
     * 作業ログ促しの表示判定とSnackbar表示
     */
    async function checkAndShowWorklogPrompt() {
        if (!currentPostId) return;
        
        try {
            // 現在の作業ログ状態を取得
            const response = await wp.apiFetch({
                path: `/wp/v2/posts/${currentPostId}?_fields=worklog_status`,
                method: 'GET'
            });
            
            const worklogStatus = response.worklog_status;
            
            if (worklogStatus && worklogStatus.should_prompt) {
                showWorklogPrompt(worklogStatus);
            }
        } catch (error) {
            console.error('[OFWN Worklog] 作業ログ状態の取得に失敗:', error);
        }
    }
    
    /**
     * 作業ログ促しSnackbarを表示
     */
    function showWorklogPrompt(worklogStatus) {
        // 既存の通知を削除
        removeNotice(worklogNoticeId);
        
        // カスタマイズ可能なメッセージ
        const message = ofwnWorklogEditor.strings.prompt_message || '今回の変更の作業ログを残しますか？';
        
        // Snackbar（通知）を表示
        createNotice('info', message, {
            id: worklogNoticeId,
            isDismissible: true,
            actions: [
                {
                    label: ofwnWorklogEditor.strings.write_now || '今すぐ書く',
                    onClick: () => {
                        removeNotice(worklogNoticeId);
                        scrollToWorklogInput();
                    }
                },
                {
                    label: ofwnWorklogEditor.strings.skip_this_time || '今回はスルー',
                    onClick: () => {
                        removeNotice(worklogNoticeId);
                        skipWorklog();
                    }
                }
            ]
        });
        
        // 自動消滅タイマー（設定可能）
        const autoHideDelay = ofwnWorklogEditor.autoHideDelay || 10000; // 10秒
        setTimeout(() => {
            removeNotice(worklogNoticeId);
        }, autoHideDelay);
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
                console.error('[OFWN Worklog] スキップに失敗:', result.data.message);
            }
        } catch (error) {
            console.error('[OFWN Worklog] スキップ処理でエラー:', error);
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
            console.error('[OFWN Worklog] 作業ログ保存でエラー:', error);
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
    
    // DOM読み込み完了時に初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWorklogPrompt);
    } else {
        initWorklogPrompt();
    }
    
    // エディタが遅れて読み込まれる場合に備えて
    setTimeout(initWorklogPrompt, 1000);
    
})();