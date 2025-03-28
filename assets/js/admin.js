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
        var isSyncing = false;

        // Prevent multiple clicks
        if (isSyncing) {
            return;
        }

        // Disable button and show spinner
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $progress.removeClass('hidden');
        isSyncing = true;
        
        // Initialize progress
        var processed = 0;
        var total = 0;
        var batchSize = parseInt($('#mdm-batch-size').val()) || 20;
        
        // Validate batch size
        batchSize = Math.min(Math.max(batchSize, 1), 100);

        function updateProgress(stats) {
            if (!stats) return;

            var progress = Math.round((stats.processed / stats.total) * 100);
            $progressBar.css('width', progress + '%');
            
            if (stats.processed >= stats.total) {
                // Format completion message with actual number
                var message = mdmAjax.i18n.sync_complete.replace('%1$d', stats.processed);
                $status.text(message);
                $spinner.removeClass('is-active');
                $button.prop('disabled', false);
            } else {
                // Format progress message with percentage
                var message = mdmAjax.i18n.syncing.replace('%1$d', progress);
                $status.text(message);
            }
        }

        function processBatch(offset) {
            if (!isSyncing) {
                return;
            }

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
                    if (!isSyncing) {
                        return;
                    }

                    if (response.success) {
                        processed = response.data.processed;
                        total = response.data.total;
                        
                        updateProgress({ processed, total });

                        if (processed < total) {
                            // Process next batch after a small delay
                            setTimeout(function() {
                                processBatch(processed);
                            }, 500);
                        } else {
                            // Sync complete
                            completeSync(total);
                        }
                    } else {
                        handleError(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    handleError(error);
                }
            });
        }

        function completeSync(total) {
            isSyncing = false;
            $button.prop('disabled', false);
            $spinner.removeClass('is-active');
            var message = mdmAjax.i18n.sync_complete.replace('%1$d', total.toString());
            $status.text(message);
            
            // Update last sync time
            var now = new Date();
            $('.mdm-last-sync-time').text(now.toLocaleString());
        }

        function handleError(error) {
            isSyncing = false;
            $button.prop('disabled', false);
            $spinner.removeClass('is-active');
            $status.text(mdmAjax.i18n.sync_error);
            console.error('Sync error:', error);
        }

        // Start processing
        processBatch(0);
    });
}); 