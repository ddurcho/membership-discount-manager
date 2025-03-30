jQuery(document).ready(function($) {
    // Cache DOM elements
    const $searchInput = $('#mdm-user-search');
    const $usersList = $('#mdm-users-list');
    
    // Debounce function for search
    function debounce(func, wait) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), wait);
        };
    }

    // Load users data
    function loadUsers(search = '') {
        $.ajax({
            url: mdmAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'mdm_get_user_data',
                nonce: mdmAjax.nonce,
                search: search
            },
            success: function(response) {
                if (response.success) {
                    renderUsers(response.data);
                } else {
                    console.error('Failed to load users:', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Ajax error:', error);
            }
        });
    }

    // Render users table
    function renderUsers(users) {
        $usersList.empty();

        users.forEach(function(user) {
            const row = `
                <tr data-user-id="${user.id}">
                    <td>
                        <strong>${user.name}</strong><br>
                        <small>${user.email}</small>
                    </td>
                    <td>
                        <select class="mdm-tier-select">
                            <option value="bronze" ${user.tier === 'bronze' ? 'selected' : ''}>Bronze (5%)</option>
                            <option value="silver" ${user.tier === 'silver' ? 'selected' : ''}>Silver (10%)</option>
                            <option value="gold" ${user.tier === 'gold' ? 'selected' : ''}>Gold (15%)</option>
                            <option value="platinum" ${user.tier === 'platinum' ? 'selected' : ''}>Platinum (20%)</option>
                        </select>
                    </td>
                    <td>
                        ${formatCurrency(user.total_spend)}
                    </td>
                    <td>
                        <label>
                            <input type="checkbox" class="mdm-override-checkbox" ${user.override ? 'checked' : ''}>
                            ${user.override ? 'Manual' : 'Automatic'}
                        </label>
                    </td>
                    <td>
                        <button class="button mdm-save-button" style="display: none;">Save Changes</button>
                    </td>
                </tr>
            `;
            $usersList.append(row);
        });
    }

    // Format currency
    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(amount);
    }

    // Handle tier or override changes
    function handleChange($row) {
        $row.find('.mdm-save-button').show();
    }

    // Save changes
    function saveChanges($row) {
        const userId = $row.data('user-id');
        const tier = $row.find('.mdm-tier-select').val();
        const override = $row.find('.mdm-override-checkbox').is(':checked');

        $.ajax({
            url: mdmAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'mdm_update_user_tier',
                nonce: mdmAjax.nonce,
                user_id: userId,
                tier: tier,
                override: override
            },
            success: function(response) {
                if (response.success) {
                    $row.find('.mdm-save-button').hide();
                    $row.find('.mdm-override-checkbox')
                        .next()
                        .text(override ? 'Manual' : 'Automatic');
                } else {
                    alert('Failed to save changes: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                alert('Failed to save changes: ' + error);
            }
        });
    }

    // Event Listeners
    $searchInput.on('input', debounce(function() {
        loadUsers($(this).val());
    }, 300));

    $usersList.on('change', '.mdm-tier-select, .mdm-override-checkbox', function() {
        handleChange($(this).closest('tr'));
    });

    $usersList.on('click', '.mdm-save-button', function() {
        saveChanges($(this).closest('tr'));
    });

    // Initial load
    loadUsers();

    // Handle membership fields sync
    $('#mdm-sync-fields').on('click', function() {
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        var $progress = $('#mdm-sync-progress');
        var $progressBar = $progress.find('.mdm-progress-fill');
        var $status = $progress.find('.mdm-progress-status');
        var batchSize = parseInt($('input[name="mdm_sync_batch_size"]').val()) || 20;
        
        // Prevent multiple clicks while syncing
        if ($button.prop('disabled')) {
            return;
        }

        // Reset progress UI
        $progressBar.css('width', '0%');
        $progress.removeClass('hidden').show();
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.text('Starting sync...');
        
        function processBatch(offset) {
            $.ajax({
                url: mdmAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mdm_sync_membership_fields',
                    nonce: mdmAjax.nonce,
                    offset: offset,
                    batch_size: batchSize
                },
                success: function(response) {
                    if (!response.success) {
                        handleError(response.data || 'Unknown error occurred during sync');
                        return;
                    }

                    if (!response.data || typeof response.data.processed === 'undefined' || typeof response.data.total === 'undefined') {
                        handleError('Invalid response format from server');
                        return;
                    }

                    var progress = Math.round((response.data.processed / response.data.total) * 100);
                    $progressBar.css('width', progress + '%');
                    
                    if (response.data.processed >= response.data.total) {
                        $status.html(
                            'Sync complete!<br>' +
                            'Processed ' + response.data.processed + ' records out of ' + response.data.total + '.'
                        );
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        
                        // Reload page after 2 seconds
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        $status.html(
                            'Syncing...<br>' +
                            'Processed ' + response.data.processed + ' out of ' + response.data.total + ' records (' + progress + '%)'
                        );
                        setTimeout(function() {
                            processBatch(response.data.processed);
                        }, 500);
                    }
                },
                error: function(xhr, status, error) {
                    var errorMessage = '';
                    try {
                        var response = JSON.parse(xhr.responseText);
                        errorMessage = response.data || error || 'Server error occurred';
                    } catch(e) {
                        errorMessage = error || 'Server error occurred';
                    }
                    handleError(errorMessage);
                }
            });
        }

        function handleError(error) {
            $status.html(
                '<span style="color: red;">Error: ' + error + '</span><br>' +
                'Please try again or contact support if the issue persists.'
            );
            $spinner.removeClass('is-active');
            $button.prop('disabled', false);
            console.error('Sync error:', error);
        }

        // Start processing
        processBatch(0);
    });

    // Handle clear logs button
    $('#mdm-clear-logs').on('click', function() {
        var $button = $(this);
        
        if ($button.prop('disabled')) {
            return;
        }

        // Show confirmation dialog
        if (!confirm(mdmAjax.i18n.confirm_clear_logs)) {
            return;
        }

        $button.prop('disabled', true)
            .addClass('updating-message')
            .text(mdmAjax.i18n.clearing_logs || 'Clearing...');
        
        $.ajax({
            url: mdmAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'mdm_clear_logs',
                nonce: mdmAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(mdmAjax.i18n.logs_cleared);
                } else {
                    alert(response.data && response.data.message ? response.data.message : mdmAjax.i18n.clear_logs_error);
                }
            },
            error: function() {
                alert(mdmAjax.i18n.clear_logs_error);
            },
            complete: function() {
                $button.prop('disabled', false)
                    .removeClass('updating-message')
                    .text(mdmAjax.i18n.clear_logs_button || 'Clear Debug Logs');
            }
        });
    });
}); 