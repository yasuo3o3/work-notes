/**
 * 作業ログ設定画面用JavaScript
 * ユーザー検索・追加・削除機能を提供
 */
(function($) {
    'use strict';
    
    let searchTimeout;
    let selectedUser = null;
    
    $(document).ready(function() {
        initUserSelector();
    });
    
    /**
     * ユーザー選択機能を初期化
     */
    function initUserSelector() {
        const $searchInput = $('#ofwn-user-search-input');
        const $addButton = $('#ofwn-add-user-btn');
        const $results = $('#ofwn-user-search-results');
        const $selectedList = $('#ofwn-selected-users-list');
        
        // 検索入力イベント
        $searchInput.on('input', function() {
            const query = $(this).val().trim();
            
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                hideResults();
                return;
            }
            
            searchTimeout = setTimeout(function() {
                searchUsers(query);
            }, 300);
        });
        
        // 追加ボタンイベント
        $addButton.on('click', function() {
            if (selectedUser) {
                addUser(selectedUser);
            }
        });
        
        // 削除ボタンイベント（動的要素）
        $selectedList.on('click', '.ofwn-remove-user', function(e) {
            e.preventDefault();
            $(this).closest('li').remove();
            updateNoUsersMessage();
        });
        
        // 検索結果クリックイベント（動的要素）
        $results.on('click', '.ofwn-search-result', function(e) {
            e.preventDefault();
            selectUser($(this));
        });
        
        // 外部クリックで検索結果を隠す
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#ofwn-user-selector').length) {
                hideResults();
            }
        });
    }
    
    /**
     * ユーザーを検索
     */
    function searchUsers(query) {
        $.ajax({
            url: ofwnWorklogSettings.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'worklog_search_users',
                nonce: ofwnWorklogSettings.nonce,
                search: query
            },
            success: function(response) {
                if (response.success && response.data.users) {
                    displaySearchResults(response.data.users);
                } else {
                    showNoResults(response.data.message || ofwnWorklogSettings.strings.no_results);
                }
            },
            error: function() {
                showNoResults(ofwnWorklogSettings.strings.search_error || '検索中にエラーが発生しました。');
            }
        });
    }
    
    /**
     * 検索結果を表示
     */
    function displaySearchResults(users) {
        const $results = $('#ofwn-user-search-results');
        const $selectedList = $('#ofwn-selected-users-list');
        const existingIds = [];
        
        // 既に選択済みのユーザーIDを取得
        $selectedList.find('li').each(function() {
            existingIds.push(parseInt($(this).data('user-id')));
        });
        
        let html = '';
        users.forEach(function(user) {
            if (existingIds.indexOf(parseInt(user.id)) === -1) {
                html += '<div class="ofwn-search-result" data-user-id="' + user.id + '">';
                html += '<strong>' + escapeHtml(user.display_name) + '</strong>';
                html += ' (' + escapeHtml(user.user_login) + ')';
                if (user.user_email) {
                    html += '<br><small>' + escapeHtml(user.user_email) + '</small>';
                }
                html += '</div>';
            }
        });
        
        if (html) {
            $results.html(html).show();
        } else {
            showNoResults(ofwnWorklogSettings.strings.no_available_users || '選択可能な新しいユーザーがありません。');
        }
    }
    
    /**
     * 検索結果なしを表示
     */
    function showNoResults(message) {
        const $results = $('#ofwn-user-search-results');
        $results.html('<div class="ofwn-no-results">' + escapeHtml(message) + '</div>').show();
    }
    
    /**
     * 検索結果を隠す
     */
    function hideResults() {
        $('#ofwn-user-search-results').hide();
        selectedUser = null;
        $('#ofwn-add-user-btn').prop('disabled', true);
    }
    
    /**
     * ユーザーを選択
     */
    function selectUser($element) {
        // 他の選択を解除
        $('.ofwn-search-result').removeClass('selected');
        $element.addClass('selected');
        
        selectedUser = {
            id: $element.data('user-id'),
            display_name: $element.find('strong').text(),
            user_login: $element.text().match(/\(([^)]+)\)/)[1]
        };
        
        $('#ofwn-add-user-btn').prop('disabled', false);
    }
    
    /**
     * ユーザーを追加
     */
    function addUser(user) {
        const $selectedList = $('#ofwn-selected-users-list');
        const $noUsers = $('.ofwn-no-users');
        
        // 既に存在するかチェック
        if ($selectedList.find('[data-user-id="' + user.id + '"]').length > 0) {
            alert(ofwnWorklogSettings.strings.already_added || 'このユーザーは既に追加されています。');
            return;
        }
        
        // リストに追加
        const html = '<li data-user-id="' + user.id + '">' +
            '<span class="ofwn-user-info">' +
            escapeHtml(user.display_name) + ' (' + escapeHtml(user.user_login) + ')' +
            '</span>' +
            '<button type="button" class="ofwn-remove-user button-link-delete">' + 
            ofwnWorklogSettings.strings.remove_user + 
            '</button>' +
            '<input type="hidden" name="of_worklog_target_user_ids[]" value="' + user.id + '" />' +
            '</li>';
        
        $selectedList.append(html);
        
        // 「ユーザーなし」メッセージを隠す
        $noUsers.hide();
        
        // 検索をリセット
        $('#ofwn-user-search-input').val('');
        hideResults();
    }
    
    /**
     * 「ユーザーなし」メッセージの表示/非表示を更新
     */
    function updateNoUsersMessage() {
        const $selectedList = $('#ofwn-selected-users-list');
        const $noUsers = $('.ofwn-no-users');
        
        if ($selectedList.children().length === 0) {
            $noUsers.show();
        } else {
            $noUsers.hide();
        }
    }
    
    /**
     * HTMLエスケープ
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
})(jQuery);