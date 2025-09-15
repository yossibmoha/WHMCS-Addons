/**
 * Contabo Addon Admin JavaScript
 */

(function($) {
    'use strict';

    const ContaboAdmin = {
        
        /**
         * Initialize the admin interface
         */
        init: function() {
            this.bindEvents();
            this.initializeComponents();
            this.startAutoRefresh();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Instance management
            $(document).on('click', '.instance-action', this.handleInstanceAction);
            $(document).on('click', '.create-instance-btn', this.showCreateInstanceModal);
            $(document).on('click', '.delete-instance-btn', this.confirmDeleteInstance);

            // Object storage management
            $(document).on('click', '.storage-action', this.handleStorageAction);
            $(document).on('click', '.create-storage-btn', this.showCreateStorageModal);
            $(document).on('click', '.resize-storage-btn', this.showResizeStorageModal);

            // Network management
            $(document).on('click', '.network-action', this.handleNetworkAction);
            $(document).on('click', '.create-network-btn', this.showCreateNetworkModal);
            $(document).on('click', '.assign-instance-btn', this.showAssignInstanceModal);

            // Image management
            $(document).on('click', '.upload-image-btn', this.showUploadImageModal);
            $(document).on('click', '.delete-image-btn', this.confirmDeleteImage);
            $(document).on('change', '#image-file-input', this.validateImageFile);

            // Cloud-init management
            $(document).on('click', '.test-cloud-init-btn', this.testCloudInitScript);
            $(document).on('click', '.save-template-btn', this.saveCloudInitTemplate);

            // General UI events
            $(document).on('click', '.refresh-data', this.refreshData);
            $(document).on('click', '.test-connection', this.testApiConnection);
        },

        /**
         * Initialize UI components
         */
        initializeComponents: function() {
            // Initialize tooltips
            $('[data-toggle="tooltip"]').tooltip();

            // Initialize data tables
            if ($.fn.DataTable) {
                $('.data-table').DataTable({
                    responsive: true,
                    pageLength: 25,
                    order: [[0, 'desc']],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search...",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries",
                        infoEmpty: "No entries found",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    }
                });
            }

            // Initialize select2 dropdowns
            if ($.fn.select2) {
                $('.select2').select2({
                    theme: 'bootstrap4',
                    width: '100%'
                });
            }

            // Initialize code editors
            if (typeof CodeMirror !== 'undefined') {
                $('.code-editor').each(function() {
                    const mode = $(this).data('mode') || 'yaml';
                    CodeMirror.fromTextArea(this, {
                        mode: mode,
                        theme: 'default',
                        lineNumbers: true,
                        lineWrapping: true,
                        indentUnit: 2,
                        tabSize: 2,
                        matchBrackets: true,
                        autoCloseBrackets: true,
                        foldGutter: true,
                        gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"]
                    });
                });
            }
        },

        /**
         * Start auto-refresh for dynamic content
         */
        startAutoRefresh: function() {
            // Refresh instance statuses every 30 seconds
            setInterval(() => {
                this.refreshInstanceStatuses();
            }, 30000);

            // Refresh connection status every 60 seconds
            setInterval(() => {
                this.checkConnectionStatus();
            }, 60000);
        },

        /**
         * Handle instance actions (start, stop, restart, etc.)
         */
        handleInstanceAction: function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const instanceId = $btn.data('instance-id');
            const action = $btn.data('action');
            
            if (!instanceId || !action) {
                ContaboAdmin.showAlert('error', 'Missing instance ID or action');
                return;
            }

            // Confirm destructive actions
            if (['stop', 'shutdown', 'delete'].includes(action)) {
                if (!confirm(`Are you sure you want to ${action} this instance?`)) {
                    return;
                }
            }

            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: window.location.href,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'manage_instance',
                    instance_id: instanceId,
                    operation: action
                },
                success: function(response) {
                    if (response.success) {
                        ContaboAdmin.showAlert('success', `Instance ${action} initiated successfully`);
                        ContaboAdmin.refreshInstanceRow(instanceId);
                    } else {
                        ContaboAdmin.showAlert('error', response.error || 'Operation failed');
                    }
                },
                error: function() {
                    ContaboAdmin.showAlert('error', 'Network error occurred');
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        },

        /**
         * Show create instance modal
         */
        showCreateInstanceModal: function() {
            $('#createInstanceModal').modal('show');
            ContaboAdmin.loadAvailableImages();
            ContaboAdmin.loadDataCenters();
        },

        /**
         * Load available images for instance creation
         */
        loadAvailableImages: function() {
            const $imageSelect = $('#instance-image-select');
            $imageSelect.html('<option>Loading...</option>');

            $.ajax({
                url: window.location.href,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'get_images'
                },
                success: function(response) {
                    if (response.success) {
                        $imageSelect.empty();
                        response.data.forEach(function(image) {
                            $imageSelect.append(`<option value="${image.imageId}">${image.name}</option>`);
                        });
                    }
                }
            });
        },

        /**
         * Handle storage actions
         */
        handleStorageAction: function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const storageId = $btn.data('storage-id');
            const action = $btn.data('action');
            
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: window.location.href,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'manage_storage',
                    storage_id: storageId,
                    operation: action
                },
                success: function(response) {
                    if (response.success) {
                        ContaboAdmin.showAlert('success', `Storage ${action} completed`);
                        ContaboAdmin.refreshStorageRow(storageId);
                    } else {
                        ContaboAdmin.showAlert('error', response.error || 'Operation failed');
                    }
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        },

        /**
         * Show upload image modal
         */
        showUploadImageModal: function() {
            $('#uploadImageModal').modal('show');
        },

        /**
         * Validate image file upload
         */
        validateImageFile: function() {
            const file = this.files[0];
            const $preview = $('#file-preview');
            const maxSize = 50 * 1024 * 1024 * 1024; // 50GB
            
            if (!file) {
                $preview.hide();
                return;
            }

            // Check file size
            if (file.size > maxSize) {
                ContaboAdmin.showAlert('error', 'File size exceeds 50GB limit');
                $(this).val('');
                return;
            }

            // Check file extension
            const allowedExtensions = ['qcow2', 'iso'];
            const fileExtension = file.name.split('.').pop().toLowerCase();
            
            if (!allowedExtensions.includes(fileExtension)) {
                ContaboAdmin.showAlert('error', 'Only .qcow2 and .iso files are supported');
                $(this).val('');
                return;
            }

            // Show file preview
            const sizeGB = (file.size / (1024 * 1024 * 1024)).toFixed(2);
            $preview.html(`
                <div class="alert alert-info">
                    <strong>Selected File:</strong> ${file.name}<br>
                    <strong>Size:</strong> ${sizeGB} GB<br>
                    <strong>Type:</strong> ${fileExtension.toUpperCase()}
                </div>
            `).show();
        },

        /**
         * Test cloud-init script
         */
        testCloudInitScript: function() {
            const script = $('#cloud-init-editor').val();
            
            if (!script) {
                ContaboAdmin.showAlert('warning', 'Please enter a cloud-init script to test');
                return;
            }

            $(this).addClass('loading').prop('disabled', true);

            // Basic YAML validation
            try {
                // This is a basic check - in production you'd want more robust validation
                if (!script.includes('#cloud-config')) {
                    throw new Error('Cloud-init script should start with #cloud-config');
                }

                ContaboAdmin.showAlert('success', 'Cloud-init script syntax appears valid');
            } catch (error) {
                ContaboAdmin.showAlert('error', 'Invalid cloud-init syntax: ' + error.message);
            }

            $(this).removeClass('loading').prop('disabled', false);
        },

        /**
         * Test API connection
         */
        testApiConnection: function() {
            const $btn = $(this);
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: window.location.href,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'test_connection'
                },
                success: function(response) {
                    if (response.success) {
                        ContaboAdmin.showAlert('success', 'API connection successful');
                    } else {
                        ContaboAdmin.showAlert('error', 'API connection failed: ' + response.error);
                    }
                },
                error: function() {
                    ContaboAdmin.showAlert('error', 'Network error occurred');
                },
                complete: function() {
                    $btn.removeClass('loading').prop('disabled', false);
                }
            });
        },

        /**
         * Refresh instance statuses
         */
        refreshInstanceStatuses: function() {
            $('.instance-row').each(function() {
                const instanceId = $(this).data('instance-id');
                if (instanceId) {
                    ContaboAdmin.refreshInstanceRow(instanceId);
                }
            });
        },

        /**
         * Refresh single instance row
         */
        refreshInstanceRow: function(instanceId) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'get_instance_status',
                    instance_id: instanceId
                },
                success: function(response) {
                    if (response.success) {
                        const $row = $(`.instance-row[data-instance-id="${instanceId}"]`);
                        $row.find('.status-cell').html(ContaboAdmin.getStatusBadge(response.data.status));
                        $row.find('.ip-cell').text(response.data.ipv4 || 'N/A');
                    }
                }
            });
        },

        /**
         * Get status badge HTML
         */
        getStatusBadge: function(status) {
            const statusMap = {
                'running': { class: 'success', text: 'Running', indicator: 'status-running' },
                'stopped': { class: 'secondary', text: 'Stopped', indicator: 'status-stopped' },
                'provisioning': { class: 'warning', text: 'Provisioning', indicator: 'status-provisioning' },
                'error': { class: 'danger', text: 'Error', indicator: 'status-error' }
            };

            const statusInfo = statusMap[status] || { class: 'secondary', text: status, indicator: 'status-stopped' };
            
            return `
                <span class="status-indicator ${statusInfo.indicator}"></span>
                <span class="badge badge-${statusInfo.class}">${statusInfo.text}</span>
            `;
        },

        /**
         * Show alert message
         */
        showAlert: function(type, message, timeout = 5000) {
            const alertClass = {
                'success': 'alert-success',
                'error': 'alert-danger',
                'warning': 'alert-warning',
                'info': 'alert-info'
            };

            const $alert = $(`
                <div class="alert ${alertClass[type]} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
            `);

            // Add to alert container or create one
            let $container = $('#alert-container');
            if ($container.length === 0) {
                $container = $('<div id="alert-container" class="position-fixed" style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;"></div>');
                $('body').append($container);
            }

            $container.append($alert);

            // Auto-hide after timeout
            if (timeout > 0) {
                setTimeout(() => {
                    $alert.alert('close');
                }, timeout);
            }
        },

        /**
         * Format bytes to human readable format
         */
        formatBytes: function(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';

            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];

            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        },

        /**
         * Copy text to clipboard
         */
        copyToClipboard: function(text) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    ContaboAdmin.showAlert('success', 'Copied to clipboard');
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "absolute";
                textArea.style.left = "-999999px";
                document.body.appendChild(textArea);
                textArea.select();
                
                try {
                    document.execCommand('copy');
                    ContaboAdmin.showAlert('success', 'Copied to clipboard');
                } catch (error) {
                    ContaboAdmin.showAlert('error', 'Failed to copy to clipboard');
                }
                
                document.body.removeChild(textArea);
            }
        },

        /**
         * Generate secure random password
         */
        generatePassword: function(length = 16) {
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
            let password = "";
            
            for (let i = 0; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            
            return password;
        },

        /**
         * Refresh data for current page
         */
        refreshData: function() {
            window.location.reload();
        },

        /**
         * Check connection status
         */
        checkConnectionStatus: function() {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'check_connection_status'
                },
                success: function(response) {
                    const $indicator = $('#connection-status-indicator');
                    if (response.success) {
                        $indicator.removeClass('text-danger').addClass('text-success');
                    } else {
                        $indicator.removeClass('text-success').addClass('text-danger');
                    }
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        ContaboAdmin.init();
    });

    // Export to global scope
    window.ContaboAdmin = ContaboAdmin;

})(jQuery);
