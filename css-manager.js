jQuery(document).ready(function ($) {
    console.log('JavaScript loaded and ready');

    // Create new CSS file
    $('#create-css-file-button').on('click', function () {
        let fileName = $('#new-css-file-name').val().trim();
        if (!fileName) {
            alert('Please enter a valid file name.');
            return;
        }

        // Ensure the file name ends with .css
        if (!fileName.endsWith('.css')) {
            fileName += '.css';
        }

        let requestData = {
            action: 'css_manager_create_file',
            file: fileName,
            security: cssManagerAjax.nonce // Correct nonce
        };

        console.log('Create file request data:', requestData);

        $.ajax({
            url: cssManagerAjax.ajaxurl,
            type: 'POST',
            data: requestData,
            success: function (response) {
                console.log('Server response:', response);
                if (response.success) {
                    alert('File created successfully');
                    location.reload(); // Refresh to see the newly created file
                } else {
                    alert('Failed to create file: ' + response.data);
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('AJAX error:', textStatus, errorThrown);
                alert('Failed to create the file due to a network or server error.');
            }
        });
    });

    // Delete CSS file
    $('body').on('click', '.css-delete-button', function () {
        console.log('Delete button clicked');
        let button = $(this);
        let fileName = button.data('file');

        if (confirm('Are you sure you want to delete ' + fileName + '? WARNING: This cannot be undone!')) {
            let requestData = {
                action: 'css_manager_delete_file',
                file: fileName,
                security: cssManagerAjax.nonce
            };

            $.ajax({
                url: cssManagerAjax.ajaxurl,
                type: 'POST',
                data: requestData,
                success: function (response) {
                    console.log('Server response:', response);
                    if (response.success) {
                        alert('File deleted successfully');
                        location.reload();
                    } else {
                        alert('Failed to delete file: ' + response.data);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('AJAX error:', textStatus, errorThrown);
                    alert('Failed to delete the file due to a network or server error.');
                }
            });
        }
    });

    // Upload a CSS file
    $('#upload-css-file-button').on('click', function () {
        let cssFile = $('#css-file-input')[0].files[0];
        if (!cssFile) {
            alert('Please select a file to upload');
            return;
        }

        let formData = new FormData();
        formData.append('action', 'css_manager_upload');
        formData.append('css_file', cssFile);
        formData.append('security', cssManagerAjax.nonce);

        $.ajax({
            url: cssManagerAjax.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.success) {
                    alert('File uploaded successfully');
                    location.reload();
                } else {
                    alert('Failed to upload: ' + response.data);
                }
            },
            error: function () {
                alert('Failed to upload the file. Please check your internet connection.');
            }
        });
    });

    // Toggle activation of CSS file
    $('.css-activation-toggle').on('change', function () {
        const fileName = $(this).data('file');
        const activate = $(this).val() === 'active';

        console.log('Sending activation update for:', fileName, 'Activate:', activate);

        $.post(cssManagerAjax.ajaxurl, {
            action: 'css_manager_toggle_activation',
            file: fileName,
            activate: activate,
            security: cssManagerAjax.nonce
        }, function (response) {
            if (response.success) {
                console.log('Activation status updated successfully for:', fileName);
            } else {
                console.error('Failed to update activation status:', response.data);
            }
        });
    });
	
	// Update priority of CSS file
    $('.css-priority-input').on('change', function () {
        const fileName = $(this).data('file');
        const newPriority = $(this).val();

        console.log('Sending priority update for:', fileName, 'New Priority:', newPriority);

        $.post(cssManagerAjax.ajaxurl, {
            action: 'css_manager_update_priority',
            file: fileName,
            priority: newPriority,
            security: cssManagerAjax.nonce
        }, function (response) {
            if (response.success) {
                console.log('Priority updated successfully for:', fileName);
            } else {
                console.error('Failed to update priority:', response.data);
            }
        });
    });

    // Capture the change event on the enqueue-location dropdowns
    // Handle changes to enqueue location dropdowns
    $('.enqueue-location').on('change', function() {
        const cssId = $(this).data('css-id');
        const newLocation = $(this).val();

        // Show/hide additional fields based on the selected value
        const parentRow = $(this).closest('tr');
        parentRow.find('.specific-pages-posts, .custom-post-type-selector, .specific-pages-save-button, .custom-post-type-save-button').remove(); // Remove any existing additional fields

        if (newLocation === 'specific') {
            parentRow.find('td').eq(3).append("<input type='text' class='specific-pages-posts' placeholder='Enter post IDs (comma-separated)' />");
            parentRow.find('td').eq(3).append("<button class='button specific-pages-save-button' data-css-id='" + cssId + "'>Save</button>");
        } else if (newLocation === 'post_type') {
            if (typeof cssManagerAjax !== 'undefined' && cssManagerAjax.post_types) {
                let postTypeDropdown = "<select class='custom-post-type-selector' data-css-id='" + cssId + "'>";
                $.each(cssManagerAjax.post_types, function(key, value) {
                    postTypeDropdown += "<option value='" + key + "'>" + value + "</option>";
                });
                postTypeDropdown += "</select>";
                parentRow.find('td').eq(3).append(postTypeDropdown);
                parentRow.find('td').eq(3).append("<button class='button custom-post-type-save-button' data-css-id='" + cssId + "'>Save</button>");
            } else {
                console.error('Post types data not found in cssManagerAjax.');
            }
        }

        // Update the enqueue location via AJAX
        $.ajax({
            url: cssManagerAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'update_enqueue_location',
                css_id: cssId,
                enqueue_location: newLocation,
                _ajax_nonce: cssManagerAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Enqueue location updated successfully.');
                } else {
                    alert('Failed to update enqueue location: ' + (response.data.message || 'Unknown error.'));
                    console.error('Error Details:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Request Failed:', error);
                alert('An error occurred during the request.');
            }
        });
    });

    // Handle save button for specific pages/posts
    $(document).on('click', '.specific-pages-save-button', function() {
        const cssId = $(this).data('css-id');
        const specificPages = $(this).siblings('.specific-pages-posts').val();

        $.ajax({
            url: cssManagerAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_specific_pages_posts',
                css_id: cssId,
                specific_pages_posts: specificPages,
                _ajax_nonce: cssManagerAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Specific Pages/Posts updated successfully.');
                } else {
                    alert('Failed to update Specific Pages/Posts: ' + (response.data.message || 'Unknown error.'));
                    console.error('Error Details:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Request Failed:', error);
                alert('An error occurred during the request.');
            }
        });
    });

    // Handle save button for custom post type
    $(document).on('click', '.custom-post-type-save-button', function() {
        const cssId = $(this).data('css-id');
        const selectedPostType = $(this).siblings('.custom-post-type-selector').val();

        $.ajax({
            url: cssManagerAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_custom_post_type',
                css_id: cssId,
                post_type_selector: selectedPostType,
                _ajax_nonce: cssManagerAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Custom Post Type updated successfully.');
                } else {
                    alert('Failed to update Custom Post Type: ' + (response.data.message || 'Unknown error.'));
                    console.error('Error Details:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Request Failed:', error);
                alert('An error occurred during the request.');
            }
        });
    });
});
