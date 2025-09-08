/**
 * 作業メモ Gutenberg サイドバー（公開ステータス直下）
 * PluginPostStatusInfo を使用して公開ステータスの直下に作業メモ入力フォームを表示
 */
(function() {
    'use strict';
    
    // デバッグ用：WordPress グローバルオブジェクトの確認
    console.log('Work Notes Debug: wp object available:', typeof wp !== 'undefined');
    console.log('Work Notes Debug: wp.element:', typeof wp.element !== 'undefined');
    console.log('Work Notes Debug: wp.editPost:', typeof wp.editPost !== 'undefined');
    console.log('Work Notes Debug: wp.plugins:', typeof wp.plugins !== 'undefined');
    
    // WordPress コンポーネントを取得
    const { createElement: e, Fragment } = wp.element;
    const { PluginPostStatusInfo } = wp.editPost;
    const { useSelect, useDispatch } = wp.data;
    const { TextControl, SelectControl, BaseControl, Button } = wp.components;
    const { useEntityProp } = wp.coreData;
    const { registerPlugin } = wp.plugins;
    const { __ } = wp.i18n;
    const { useState, useEffect } = wp.element;
    
    // 作業メモサイドバーコンポーネント
    function WorkNotesPanel() {
        // 投稿タイプとIDを取得
        const postType = useSelect(select => 
            select('core/editor').getCurrentPostType(), []
        );
        const postId = useSelect(select => 
            select('core/editor').getCurrentPostId(), []
        );
        
        // 投稿と固定ページでない場合は表示しない
        const targetPostTypes = ['post', 'page'];
        if (!targetPostTypes.includes(postType)) {
            return null;
        }
        
        // 折りたたみ状態の管理（初期状態は展開）
        const [isExpanded, setIsExpanded] = useState(true);
        
        // 初期値適用フラグ（初回マウント1回のみ）
        const [prefillApplied, setPrefillApplied] = useState(false);
        const [backfillProcessed, setBackfillProcessed] = useState(false);
        
        // Phase 2: CPT直接アクセス用のhooks
        const { getEntityRecord, editEntityRecord, saveEntityRecord } = useDispatch('core');
        
        // Phase 2: 親投稿のメタデータから _ofwn_bound_cpt_id を取得
        const [meta, setMeta] = useEntityProp('postType', postType, 'meta', postId);
        const boundCptId = meta?._ofwn_bound_cpt_id;
        
        // Phase 2: CPT直接取得
        const workNote = useSelect(
            (select) => {
                if (!boundCptId) return null;
                return select('core').getEntityRecord('postType', 'of_work_note', boundCptId);
            },
            [boundCptId]
        );
        
        // Phase 2: CPT作成・保存処理の状態管理
        const [isCreatingCpt, setIsCreatingCpt] = useState(false);
        const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
        
        // Phase 2: 現在の値を取得（CPT優先、フォールバック：親メタ）
        const currentTargetType = workNote?.meta?._ofwn_target_type || meta?._ofwn_target_type || '';
        const currentTargetId = workNote?.meta?._ofwn_target_id || meta?._ofwn_target_id || '';
        const currentRequester = workNote?.meta?._ofwn_requester || meta?._ofwn_requester || '';
        const currentWorker = workNote?.meta?._ofwn_worker || meta?._ofwn_worker || '';
        const currentStatus = workNote?.meta?._ofwn_status || meta?._ofwn_status || '依頼';
        const currentWorkDate = workNote?.meta?._ofwn_work_date || meta?._ofwn_work_date || new Date().toISOString().split('T')[0];
        // CPT優先：post_title/post_content → メタ → 旧データ
        const currentWorkTitle = workNote?.title?.rendered || workNote?.meta?._ofwn_work_title || meta?._ofwn_work_title || meta?._ofwn_target_label || '';
        const currentWorkContent = workNote?.content?.rendered || workNote?.meta?._ofwn_work_content || meta?._ofwn_work_content || '';
        
        // Phase 2: CPT作成・更新ヘルパー関数
        const createOrUpdateWorkNote = async function(updates) {
            setHasUnsavedChanges(true);
            
            // CPT未作成の場合は先に作成
            if (!boundCptId) {
                setIsCreatingCpt(true);
                try {
                    // サーバーサイドのget_or_create_work_note_cpt()呼び出し
                    const response = await wp.apiFetch({
                        path: `/wp/v2/of_work_note`,
                        method: 'POST',
                        data: {
                            status: 'publish',
                            title: updates._ofwn_work_title || '',
                            content: updates._ofwn_work_content || '',
                            meta: {
                                _ofwn_bound_post_id: postId,
                                _ofwn_target_type: updates._ofwn_target_type || '',
                                _ofwn_target_id: updates._ofwn_target_id || '',
                                _ofwn_requester: updates._ofwn_requester || '',
                                _ofwn_worker: updates._ofwn_worker || '',
                                _ofwn_status: updates._ofwn_status || '依頼',
                                _ofwn_work_date: updates._ofwn_work_date || new Date().toISOString().split('T')[0]
                            }
                        }
                    });
                    
                    // 親投稿に _ofwn_bound_cpt_id を設定
                    setMeta({ ...meta, _ofwn_bound_cpt_id: response.id });
                    
                } catch (error) {
                    console.error('Work Notes: CPT作成エラー', error);
                } finally {
                    setIsCreatingCpt(false);
                    setHasUnsavedChanges(false);
                }
            } else {
                // 既存CPT更新
                try {
                    const updateData = {};
                    
                    // タイトル・内容の更新
                    if (updates._ofwn_work_title !== undefined) {
                        updateData.title = updates._ofwn_work_title;
                    }
                    if (updates._ofwn_work_content !== undefined) {
                        updateData.content = updates._ofwn_work_content;
                    }
                    
                    // メタ更新
                    const metaUpdates = {};
                    ['_ofwn_target_type', '_ofwn_target_id', '_ofwn_requester', '_ofwn_worker', '_ofwn_status', '_ofwn_work_date'].forEach(key => {
                        if (updates[key] !== undefined) {
                            metaUpdates[key] = updates[key];
                        }
                    });
                    
                    if (Object.keys(metaUpdates).length > 0) {
                        updateData.meta = metaUpdates;
                    }
                    
                    if (Object.keys(updateData).length > 0) {
                        await wp.apiFetch({
                            path: `/wp/v2/of_work_note/${boundCptId}`,
                            method: 'POST',
                            data: updateData
                        });
                    }
                    
                } catch (error) {
                    console.error('Work Notes: CPT更新エラー', error);
                } finally {
                    setHasUnsavedChanges(false);
                }
            }
        };
        
        // 旧式メタ更新（Phase 1互換性用）
        const updateMeta = function(key, value) {
            setMeta({ ...meta, [key]: value });
        };
        
        // 初期値適用処理（初回マウント時のみ）
        wp.element.useEffect(() => {
            if (prefillApplied || !window.ofwnPrefill || !meta) {
                return;
            }
            
            const prefillData = window.ofwnPrefill;
            let hasUpdates = false;
            const updates = { ...meta };
            
            // 空フィールドにのみ初期値を適用
            if (prefillData.target_type && (!meta._ofwn_target_type || meta._ofwn_target_type === '')) {
                updates._ofwn_target_type = prefillData.target_type;
                hasUpdates = true;
            }
            
            if (prefillData.target_id && (!meta._ofwn_target_id || meta._ofwn_target_id === '')) {
                updates._ofwn_target_id = prefillData.target_id;
                hasUpdates = true;
            }
            
            if (prefillData.requester && (!meta._ofwn_requester || meta._ofwn_requester === '')) {
                updates._ofwn_requester = prefillData.requester;
                hasUpdates = true;
            }
            
            if (prefillData.worker && (!meta._ofwn_worker || meta._ofwn_worker === '')) {
                updates._ofwn_worker = prefillData.worker;
                hasUpdates = true;
            }
            
            if (hasUpdates) {
                setMeta(updates);
                console.log('Work Notes: 初期値を適用しました', updates);
            }
            
            setPrefillApplied(true);
            
            // lazy backfill処理
            if (prefillData.needs_backfill && prefillData.note_id && prefillData.current_post_id && !backfillProcessed) {
                setBackfillProcessed(true);
                
                // wp.apiFetch でbackfill実行
                wp.apiFetch({
                    path: '/wp/v2/of_work_note/' + prefillData.note_id,
                    method: 'POST',
                    data: {
                        meta: {
                            _ofwn_bound_post_id: prefillData.current_post_id
                        }
                    }
                }).then(response => {
                    console.log('Work Notes: lazy backfill完了', response);
                }).catch(error => {
                    console.warn('Work Notes: lazy backfill失敗', error);
                });
            }
            
        }, [meta, prefillApplied, backfillProcessed]);
        
        // 依頼元・担当者のオプション（サーバーから取得）
        const requesterOptions = window.ofwnGutenbergData?.requesters || [];
        const workerOptions = window.ofwnGutenbergData?.workers || [];
        
        // セレクトオプションを構築
        const requesterSelectOptions = [
            { label: '（選択）', value: '' },
            ...requesterOptions.map(option => ({ label: option, value: option })),
            { label: 'その他（手入力）', value: '__custom__' }
        ];
        
        const workerSelectOptions = [
            { label: '（選択）', value: '' },
            ...workerOptions.map(option => ({ label: option, value: option })),
            { label: 'その他（手入力）', value: '__custom__' }
        ];
        
        return e(PluginPostStatusInfo, {
            className: 'work-notes-post-status-info'
        }, 
            // 単一ラッパー（PluginPostStatusInfoの唯一の直下子要素）
            e('div', {
                className: 'work-notes-container'
            },
                // タイトル行（クリックで展開・折りたたみ）
                e(Button, {
                    variant: 'tertiary',
                    onClick: () => setIsExpanded(!isExpanded),
                    className: 'work-notes-toggle-button',
                    'aria-expanded': isExpanded
                }, __('作業メモ属性', 'work-notes') + (isExpanded ? ' ▲' : ' ▼')),
                
                // 折りたたみ可能なコンテンツ
                isExpanded && e(Fragment, {},
                    // 対象タイプ
                    e(SelectControl, {
                        label: __('対象タイプ', 'work-notes'),
                        className: 'work-notes-field',
                        value: currentTargetType,
                        options: [
                            { label: __('（任意）', 'work-notes'), value: '' },
                            { label: __('投稿/固定ページ', 'work-notes'), value: 'post' },
                            { label: __('サイト全体/設定/テーマ', 'work-notes'), value: 'site' },
                            { label: __('その他', 'work-notes'), value: 'other' }
                        ],
                        onChange: function(value) {
                            createOrUpdateWorkNote({ _ofwn_target_type: value });
                        }
                    }),
                    
                    // 対象ID
                    e(TextControl, {
                        label: __('対象ID（投稿IDなど）', 'work-notes'),
                        className: 'work-notes-field',
                        value: currentTargetId,
                        onChange: function(value) {
                            createOrUpdateWorkNote({ _ofwn_target_id: value });
                        }
                    }),
                    
                    
                    // 依頼元
                    e(SelectControl, {
                        label: __('依頼元', 'work-notes'),
                        className: 'work-notes-field ofwn-inline',
                        value: requesterSelectOptions.find(opt => opt.value === currentRequester) ? currentRequester : '__custom__',
                        options: requesterSelectOptions,
                        onChange: function(value) {
                            if (value === '__custom__') {
                                createOrUpdateWorkNote({ _ofwn_requester: '' });
                            } else {
                                createOrUpdateWorkNote({ _ofwn_requester: value });
                            }
                        }
                    }),
                    
                    // 依頼元カスタム入力（セレクトで__custom__が選択されている場合のみ表示）
                    !requesterSelectOptions.find(opt => opt.value === currentRequester) &&
                    e(TextControl, {
                        label: __('依頼元（手入力）', 'work-notes'),
                        className: 'work-notes-field',
                        value: currentRequester,
                        onChange: function(value) {
                            createOrUpdateWorkNote({ _ofwn_requester: value });
                        }
                    }),
                    
                    // 担当者
                    e(SelectControl, {
                        label: __('担当者', 'work-notes'),
                        className: 'work-notes-field ofwn-inline',
                        value: workerSelectOptions.find(opt => opt.value === currentWorker) ? currentWorker : '__custom__',
                        options: workerSelectOptions,
                        onChange: function(value) {
                            if (value === '__custom__') {
                                createOrUpdateWorkNote({ _ofwn_worker: '' });
                            } else {
                                createOrUpdateWorkNote({ _ofwn_worker: value });
                            }
                        }
                    }),
                    
                    // 担当者カスタム入力
                    !workerSelectOptions.find(opt => opt.value === currentWorker) &&
                    e(TextControl, {
                        label: __('担当者（手入力）', 'work-notes'),
                        className: 'work-notes-field',
                        value: currentWorker,
                        onChange: function(value) {
                            createOrUpdateWorkNote({ _ofwn_worker: value });
                        }
                    }),
                    
                    // ステータス
                    e(SelectControl, {
                        label: __('ステータス', 'work-notes'),
                        className: 'work-notes-field ofwn-inline',
                        value: currentStatus,
                        options: [
                            { label: __('依頼', 'work-notes'), value: '依頼' },
                            { label: __('対応中', 'work-notes'), value: '対応中' },
                            { label: __('完了', 'work-notes'), value: '完了' }
                        ],
                        onChange: function(value) {
                            createOrUpdateWorkNote({ _ofwn_status: value });
                        }
                    }),
                    
                    // 実施日
                    e(TextControl, {
                        label: __('実施日', 'work-notes'),
                        className: 'work-notes-field ofwn-inline',
                        type: 'date',
                        value: currentWorkDate,
                        onChange: function(value) {
                            createOrUpdateWorkNote({ _ofwn_work_date: value });
                        }
                    }),
                    
                    // 作業タイトル（2行入力）
                    e(TextControl, {
                        label: __('作業タイトル', 'work-notes'),
                        className: 'work-notes-field',
                        value: currentWorkTitle,
                        rows: 2,
                        onChange: function(value) {
                            createOrUpdateWorkNote({ _ofwn_work_title: value });
                        }
                    }),
                    
                    // 新規追加: 作業内容
                    e(TextControl, {
                        label: __('作業内容', 'work-notes'),
                        className: 'work-notes-field',
                        rows: 3,
                        value: currentWorkContent,
                        onChange: function(value) {
                            createOrUpdateWorkNote({ _ofwn_work_content: value });
                        }
                    })
                )
            )
        );
    }
    
    // プラグイン登録
    registerPlugin('work-notes-sidebar', {
        render: WorkNotesPanel,
        icon: 'clipboard'
    });
    
})();