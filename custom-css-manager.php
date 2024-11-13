<?php
/*
Plugin Name: Custom CSS Manager
Plugin URI: https://github.com/carterfromsl/css-manager/
Description: Allows custom CSS file creation, editing, and deletion, registering files to load in the header with cache-busting. Install StratLab Updator for auto-updates.
Version: 1.7.6.3
Author: StratLab Marketing
Author URI: https://strategylab.ca
Text Domain: css-manager
Requires at least: 6.0
Requires PHP: 7.0
Update URI: https://github.com/carterfromsl/css-manager/
*/

// Create the directory for CSS files if it doesn't exist
function css_manager_create_directory() {
    $upload_dir = wp_upload_dir();
    $css_dir = $upload_dir['basedir'] . '/css-manager/';
    if (!is_dir($css_dir)) {
        wp_mkdir_p($css_dir);
    }
}
register_activation_hook(__FILE__, 'css_manager_create_directory');

function create_css_manager_table() {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'css_manager';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        file_name varchar(255) NOT NULL,
        last_edited datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        active boolean DEFAULT 0 NOT NULL,
        enqueue_location varchar(50) DEFAULT 'everywhere' NOT NULL,
		priority int DEFAULT 10 NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('after_setup_theme', 'create_css_manager_table');

// Connect with the StratLab Auto-Updater for plugin updates
add_action('plugins_loaded', function () {
    if (class_exists('StratLabUpdater')) {
        $plugin_file = __FILE__;
        $plugin_data = get_plugin_data($plugin_file);

        do_action('stratlab_register_plugin', [
            'slug' => plugin_basename($plugin_file),
            'repo_url' => 'https://api.github.com/repos/carterfromsl/css-manager/releases/latest',
            'version' => $plugin_data['Version'], =
            'name' => $plugin_data['Name'],
            'author' => $plugin_data['Author'],
            'homepage' => $plugin_data['PluginURI'],
            'description' => $plugin_data['Description'], 
        ]);
    }
});

// Register AJAX handlers for create, edit, delete
add_action('wp_ajax_css_manager_save_file', 'css_manager_save_file');
add_action('wp_ajax_css_manager_delete_file', 'css_manager_delete_file');
add_action('wp_ajax_css_manager_create_file', 'css_manager_create_file');
add_action('wp_ajax_css_manager_toggle_activation', 'css_manager_toggle_activation');
add_action('wp_ajax_css_manager_load_file', 'css_manager_load_file');

// Enqueue JavaScript for handling AJAX requests
function css_manager_enqueue_admin_scripts() {
    // Enqueue jQuery
    wp_enqueue_script('jquery');

    // Enqueue your custom script
    wp_enqueue_script('css-manager-js', plugins_url('/css-manager.js', __FILE__), array('jquery'), '1.0', true);

    // Fetch public post types to use for the custom post type dropdown
    $post_types = get_post_types(['public' => true], 'objects');
    $post_types_formatted = [];
    foreach ($post_types as $key => $type) {
        $post_types_formatted[$key] = $type->label;
    }

    // Pass data to JavaScript
    wp_localize_script('css-manager-js', 'cssManagerAjax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('css_manager_nonce'),
        'post_types' => $post_types_formatted
    ]);
}
add_action('admin_enqueue_scripts', 'css_manager_enqueue_admin_scripts');

function css_manager_create_file() {
    error_log('Create file request started'); // Log beginning of the create process

    // Verify the nonce for security purposes
    if (!check_ajax_referer('css_manager_nonce', 'security', false)) {
        error_log('Nonce verification failed for file creation'); // Log nonce failure
        wp_send_json_error('Invalid nonce', 400);
        return;
    }

    // Check if the user has permission to create files
    if (!current_user_can('upload_files')) { // Use upload_files as it's more suitable for file creation
        error_log('User does not have permission to create files'); // Log permission failure
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    // Verify that a file name was provided
    if (empty($_POST['file'])) {
        error_log('No file name specified in request'); // Log missing file name
        wp_send_json_error('No file name specified', 400);
        return;
    }

    $file = sanitize_file_name($_POST['file']);
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/css-manager/' . $file;

    // Log the file path that is going to be created
    error_log('Attempting to create file: ' . $file_path);

    // Ensure the directory exists
    if (!is_dir($upload_dir['basedir'] . '/css-manager/')) {
        error_log('CSS manager directory does not exist, attempting to create it');
        if (!wp_mkdir_p($upload_dir['basedir'] . '/css-manager/')) {
            error_log('Failed to create directory: ' . $upload_dir['basedir'] . '/css-manager/');
            wp_send_json_error('Failed to create directory for files', 500);
            return;
        }
    }

    // Check if the file already exists
    if (file_exists($file_path)) {
        error_log('File already exists: ' . $file_path); // Log if file already exists
        wp_send_json_error('File already exists', 409);
        return;
    }

    // Create a new empty CSS file
    if (file_put_contents($file_path, '') !== false) {
        error_log('File created successfully: ' . $file_path); // Log successful creation
        file_put_contents($file_path, "/* New CSS file: $file */\n");

        // Insert new record into the database
        global $wpdb;
        $table_name = $wpdb->base_prefix . 'css_manager';
        $inserted = $wpdb->insert(
            $table_name,
            array(
                'file_name' => $file,
                'last_edited' => current_time('mysql'),
                'active' => 0,
                'enqueue_location' => 'everywhere'
            ),
            array('%s', '%s', '%d', '%s')
        );

        if ($inserted === false) {
            error_log("Failed to insert new CSS file into database after creation: " . $wpdb->last_error);
            wp_send_json_error('Failed to register the new file in the database.', 500);
        } else {
            wp_send_json_success('File created and registered successfully');
        }
    } else {
        error_log('Failed to create file: ' . $file_path); // Log failure to create file
        wp_send_json_error('Failed to create the file. Please check folder permissions.', 500);
    }
}
add_action('wp_ajax_css_manager_create_file', 'css_manager_create_file');

// Load an existing CSS file
function css_manager_load_file() {
    check_ajax_referer('css_manager_nonce', 'security');
    $file_name = sanitize_file_name($_POST['file']);

    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/css-manager/' . $file_name;

    if (file_exists($file_path)) {
        $content = file_get_contents($file_path);
        wp_send_json_success(array('content' => $content));
    } else {
        wp_send_json_error('File does not exist.');
    }
}

// Delete an existing CSS file
function css_manager_delete_file() {
    check_ajax_referer('css_manager_nonce', 'security');

    if (empty($_POST['file'])) {
        wp_send_json_error('No file name specified', 400);
        return;
    }

    $file_name = sanitize_text_field($_POST['file']);
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/css-manager/' . $file_name;

    // Delete the file from the directory
    if (file_exists($file_path) && !unlink($file_path)) {
        error_log('Failed to delete file: ' . $file_path);
        wp_send_json_error('Failed to delete file', 500);
        return;
    }

    // Delete the record from the database
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'css_manager';
    $deleted = $wpdb->delete(
        $table_name,
        array('file_name' => $file_name),
        array('%s')
    );

    if ($deleted === false) {
        error_log("Failed to delete file record from database: " . $wpdb->last_error);
        wp_send_json_error('Failed to delete file record from the database.', 500);
    } else {
        error_log("File and record deleted successfully: " . $file_name);
        wp_send_json_success('File and record deleted successfully');
    }
}
add_action('wp_ajax_css_manager_delete_file', 'css_manager_delete_file');

// Toggle activation status via AJAX
function css_manager_toggle_activation() {
    check_ajax_referer('css_manager_nonce', 'security');
    
    $file_name = sanitize_text_field($_POST['file']);
    $activate = filter_var($_POST['activate'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

    global $wpdb;
    $table_name = $wpdb->base_prefix . 'css_manager';

    // Debug log to check incoming values and table name
    error_log("Activating file: " . $file_name . " - Activate: " . ($activate ? 'true' : 'false'));
    error_log("Using table: " . $table_name);

    // Perform the update in the database using a direct SQL query
    $sql = $wpdb->prepare(
        "UPDATE $table_name SET active = %d WHERE file_name = %s",
        $activate,
        $file_name
    );
    $updated = $wpdb->query($sql);

    // Additional verification to log the result of the update operation
    if ($updated !== false) {
        if ($updated > 0) {
            error_log("Update successful for file: " . $file_name);
        } else {
            error_log("No rows affected. File may already have the desired activation status: " . $file_name);
        }
        // Verify the status after update
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT active FROM $table_name WHERE file_name = %s", $file_name)
        );
        if ($result) {
            error_log("Post-update status for file " . $file_name . ": " . $result->active);
        } else {
            error_log("Failed to verify update for file: " . $file_name);
        }
        wp_send_json_success('Activation status updated.');
    } else {
        // Log detailed error message if update fails
        error_log("Failed to update activation for file: " . $file_name);
        error_log("WPDB Last Error: " . $wpdb->last_error);
        error_log("SQL Query: " . $wpdb->last_query);
        wp_send_json_error('Failed to update activation status.');
    }
}
add_action('wp_ajax_css_manager_toggle_activation', 'css_manager_toggle_activation');

// AJAX handler to update CSS priority
function css_manager_update_priority() {
    check_ajax_referer('css_manager_nonce', 'security');
    
    $file_name = sanitize_text_field($_POST['file']);
    $priority = intval($_POST['priority']);

    global $wpdb;
    $table_name = $wpdb->base_prefix . 'css_manager';

    // Debug log to check incoming values and table name
    error_log("Updating priority for file: " . $file_name . " - New Priority: " . $priority);
    error_log("Using table: " . $table_name);

    // Perform the update in the database using a direct SQL query
    $sql = $wpdb->prepare(
        "UPDATE $table_name SET priority = %d WHERE file_name = %s",
        $priority,
        $file_name
    );
    $updated = $wpdb->query($sql);

    // Additional verification to log the result of the update operation
    if ($updated !== false) {
        if ($updated > 0) {
            error_log("Update successful for file: " . $file_name);
        } else {
            error_log("No rows affected. File may already have the desired priority: " . $file_name);
        }
        // Verify the priority after update
        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT priority FROM $table_name WHERE file_name = %s", $file_name)
        );
        if ($result) {
            error_log("Post-update priority for file " . $file_name . ": " . $result->priority);
        } else {
            error_log("Failed to verify update for file: " . $file_name);
        }
        wp_send_json_success('Priority updated.');
    } else {
        // Log detailed error message if update fails
        error_log("Failed to update priority for file: " . $file_name);
        error_log("WPDB Last Error: " . $wpdb->last_error);
        error_log("SQL Query: " . $wpdb->last_query);
        wp_send_json_error('Failed to update priority.');
    }
}
add_action('wp_ajax_css_manager_update_priority', 'css_manager_update_priority');

// Get CSS file activation status
function css_manager_get_status() {
    check_ajax_referer('css_manager_nonce', 'security');

    if (empty($_GET['file'])) {
        wp_send_json_error('No file name specified', 400);
        return;
    }

    $file_name = sanitize_text_field($_GET['file']);

    global $wpdb;
    $table_name = $wpdb->base_prefix . 'css_manager';

    // Retrieve the activation status from the database
    $result = $wpdb->get_row(
        $wpdb->prepare("SELECT active FROM $table_name WHERE file_name = %s", $file_name)
    );

    if ($result === null) {
        error_log("Failed to find file in database: " . $file_name);
        wp_send_json_error('File not found', 404);
        return;
    }

    wp_send_json_success(array('active' => (bool) $result->active));
}
add_action('wp_ajax_css_manager_get_status', 'css_manager_get_status');

// Add Admin Page for CSS Manager
function css_manager_admin_menu() {
    add_menu_page(
        'CSS Manager',
        'CSS Manager',
        'manage_options',
        'css-manager',
        'css_manager_admin_page',
        'dashicons-editor-code',
        100
    );
}
add_action('admin_menu', 'css_manager_admin_menu');

// Create location select dropdown
function render_enqueue_location_dropdown($current_value, $id) {
    $options = [
        'everywhere' => 'Everywhere (Frontend)',
        'admin' => 'WordPress Admin (Backend)',
        'pages' => 'Pages',
        'posts' => 'Posts',
        'archives' => 'Archives',
        'homepage' => 'Homepage',
        'specific' => 'Specific Pages/Posts',
        'post_type' => 'Custom Post Type'
    ];
    
    echo "<select class='enqueue-location' style='max-width: 100%' data-css-id='" . esc_attr($id) . "'>";
    foreach ($options as $key => $label) {
        $selected = ($current_value === $key) ? 'selected' : '';
        echo "<option value='" . esc_attr($key) . "' $selected>" . esc_html($label) . "</option>";
    }
    echo "</select>";

    // Render additional input fields based on the current value
    if ($current_value === 'specific') {
        $specific_ids = esc_attr(get_post_meta($id, '_specific_pages_posts', true));
        echo "<input type='text' class='specific-pages-posts' placeholder='Enter post IDs (comma-separated)' value='$specific_ids' />";
        echo "<button class='button specific-pages-save-button' data-css-id='" . esc_attr($id) . "'>Save</button>";
    } elseif ($current_value === 'post_type') {
        $post_types = get_post_types(['public' => true], 'objects');
        $selected_post_type = esc_attr(get_post_meta($id, '_custom_post_type', true));
        echo "<select class='custom-post-type-selector' data-css-id='" . esc_attr($id) . "'>";
        foreach ($post_types as $post_type) {
            $selected = ($selected_post_type === $post_type->name) ? 'selected' : '';
            echo "<option value='" . esc_attr($post_type->name) . "' $selected>" . esc_html($post_type->label) . "</option>";
        }
        echo "</select>";
        echo "<button class='button custom-post-type-save-button' data-css-id='" . esc_attr($id) . "'>Save</button>";
    }
}

// AJAX handler for location selection
add_action('wp_ajax_update_enqueue_location', 'update_enqueue_location');
function update_enqueue_location() {
    global $wpdb;

    // Verify the nonce
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'css_manager_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        wp_die();
    }

    $css_id = isset($_POST['css_id']) ? intval($_POST['css_id']) : 0;
    $enqueue_location = isset($_POST['enqueue_location']) ? sanitize_text_field($_POST['enqueue_location']) : '';

    // Ensure valid data is received
    if ($css_id <= 0 || empty($enqueue_location)) {
        wp_send_json_error(['message' => 'Invalid CSS ID or Enqueue Location received.']);
        wp_die();
    }

    // Prepare additional meta data if specific pages or custom post type is selected
    $additional_meta = [];
    if ($enqueue_location === 'specific') {
        $specific_ids = isset($_POST['specific_pages_posts']) ? sanitize_text_field($_POST['specific_pages_posts']) : '';
        $additional_meta['_specific_pages_posts'] = $specific_ids;
        update_post_meta($css_id, '_specific_pages_posts', $specific_ids);
    } elseif ($enqueue_location === 'post_type') {
        $selected_post_type = isset($_POST['post_type_selector']) ? sanitize_text_field($_POST['post_type_selector']) : '';
        $additional_meta['_custom_post_type'] = $selected_post_type;
        update_post_meta($css_id, '_custom_post_type', $selected_post_type);
    }

    // Update the main table with the enqueue location
    $table_name = $wpdb->prefix . 'css_manager';
    $updated = $wpdb->update(
        $table_name,
        ['enqueue_location' => $enqueue_location],
        ['id' => $css_id],
        ['%s'],
        ['%d']
    );

    if ($updated === false) {
        wp_send_json_error(['message' => 'Database update failed.']);
    } elseif ($updated === 0) {
        wp_send_json_error(['message' => 'No changes were made.']);
    } else {
        wp_send_json_success(['message' => 'Enqueue location updated successfully.']);
    }

    wp_die();
}
// Handle saving specific pages/posts
add_action('wp_ajax_save_specific_pages_posts', 'save_specific_pages_posts');
function save_specific_pages_posts() {
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'css_manager_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        wp_die();
    }

    $css_id = isset($_POST['css_id']) ? intval($_POST['css_id']) : 0;
    $specific_pages_posts = isset($_POST['specific_pages_posts']) ? sanitize_text_field($_POST['specific_pages_posts']) : '';

    if ($css_id > 0) {
        update_post_meta($css_id, '_specific_pages_posts', $specific_pages_posts);
        wp_send_json_success(['message' => 'Specific pages/posts saved successfully.']);
    } else {
        wp_send_json_error(['message' => 'Invalid CSS ID.']);
    }

    wp_die();
}

// Handle saving custom post type
add_action('wp_ajax_save_custom_post_type', 'save_custom_post_type');
function save_custom_post_type() {
    if (!isset($_POST['_ajax_nonce']) || !wp_verify_nonce($_POST['_ajax_nonce'], 'css_manager_nonce')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        wp_die();
    }

    $css_id = isset($_POST['css_id']) ? intval($_POST['css_id']) : 0;
    $post_type_selector = isset($_POST['post_type_selector']) ? sanitize_text_field($_POST['post_type_selector']) : '';

    if ($css_id > 0) {
        update_post_meta($css_id, '_custom_post_type', $post_type_selector);
        wp_send_json_success(['message' => 'Custom post type saved successfully.']);
    } else {
        wp_send_json_error(['message' => 'Invalid CSS ID.']);
    }

    wp_die();
}

// Admin Page Content
function css_manager_admin_page() {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $css_dir = $upload_dir['basedir'] . '/css-manager/';
    $css_files = glob($css_dir . '*.css');
    $table_name = $wpdb->prefix . 'css_manager';

    // Retrieve all data from the database related to the CSS files
    $css_file_data = $wpdb->get_results("SELECT * FROM $table_name");

    // Create a lookup array for CSS files based on the file name
    $css_file_data_lookup = [];
    foreach ($css_file_data as $css) {
        $css_file_data_lookup[$css->file_name] = $css;
    }

    ?>
    <div class="wrap">
        <h1>CSS Manager</h1>
        <p>Use this panel to create, edit, and manage custom CSS files for this website.</p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>File Name</th>
                    <th>Last Edited</th>
                    <th>Status</th>
                    <th>Location</th>
                    <th>Priority</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($css_files) : ?>
                    <?php foreach ($css_files as $file) : ?>
                        <?php 
                            $file_name = basename($file);
                            // Skip minified files (.min.css)
                            if (strpos($file_name, '.min.css') !== false) {
                                continue;
                            }
                            // Retrieve data from the lookup array for the current file
                            $file_data = isset($css_file_data_lookup[$file_name]) ? $css_file_data_lookup[$file_name] : null;
                            $is_active = $file_data ? (bool) $file_data->active : false;
                            $enqueue_location = $file_data ? $file_data->enqueue_location : 'everywhere'; // Default to "everywhere"
                            $priority = $file_data ? intval($file_data->priority) : 10; // Default priority to 10
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url($upload_dir['baseurl'] . '/css-manager/' . $file_name); ?>" target="_blank"><?php echo esc_html($file_name); ?></a></td>
                            <td><?php echo esc_html(date("F d Y H:i:s", filemtime($file))); ?></td>
                            <td>
                                <select class="css-activation-toggle" data-file="<?php echo esc_attr($file_name); ?>">
                                    <option value="active" <?php echo $is_active ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo !$is_active ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </td>
                            <td>
                                <?php render_enqueue_location_dropdown($enqueue_location, $file_data ? $file_data->id : 0); ?>
                            </td>
                            <td>
                                <input type="number" class="css-priority-input" style="width: 80px" data-file="<?php echo esc_attr($file_name); ?>" value="<?php echo esc_attr($priority); ?>" min="0">
                            </td>
                            <td>
                                <a href="admin.php?page=css_manager_edit&file=<?php echo urlencode(esc_attr($file_name)); ?>" class="button css-edit-button">Edit</a>
                                <button class="button css-delete-button" data-file="<?php echo esc_attr($file_name); ?>">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6">No CSS files found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="postbox" style="margin-top: 30px;">
            <div class="inside">
                <h3>Create New CSS File</h3>
                <p>
                    <input type="text" id="new-css-file-name" placeholder="custom-style.css">
                    <button class="button button-primary" id="create-css-file-button">Create File</button>
                </p>
                <hr/>
                <h3>Upload a CSS File</h3>
                <p><input type="file" id="css-file-input" accept=".css" /></p>
                <button class="button button-primary" id="upload-css-file-button">Upload CSS File</button>
            </div>
        </div>
    </div>
    <?php
}

// Handle CSS File Upload with AJAX
function css_manager_upload_file() {
    check_ajax_referer('css_manager_nonce', 'security');

    if (!isset($_FILES['css_file'])) {
        wp_send_json_error('No file provided.');
    }

    $uploaded_file = $_FILES['css_file'];
    $file_name = sanitize_file_name($uploaded_file['name']);

    // Ensure file has a .css extension
    if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'css') {
        wp_send_json_error('Invalid file type. Only .css files are allowed.');
    }

    $upload_dir = wp_upload_dir();
    $css_dir = $upload_dir['basedir'] . '/css-manager/';
    $file_path = $css_dir . $file_name;

    if (!move_uploaded_file($uploaded_file['tmp_name'], $file_path)) {
        wp_send_json_error('Failed to move uploaded file.');
    }

    // Insert new record into the database
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'css_manager';

    // Debug to check if table name and file name are correct
    error_log("Inserting new file into table: " . $table_name . " - File: " . $file_name);

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'file_name' => $file_name,
            'last_edited' => current_time('mysql'),
            'active' => 0,
            'enqueue_location' => 'everywhere'
        ),
        array('%s', '%s', '%d', '%s')
    );

    if ($inserted === false) {
        error_log("Failed to insert new CSS file into database: " . $wpdb->last_error);
        error_log("SQL Query: " . $wpdb->last_query);
        wp_send_json_error('Failed to register the uploaded file in the database.');
    } else {
        error_log("File uploaded and registered successfully: " . $file_name);
        wp_send_json_success('File uploaded and registered successfully.');
    }
}
add_action('wp_ajax_css_manager_upload', 'css_manager_upload_file');

// Add sidebar navigation to single file editor listing all CSS files
function css_manager_sidebar_nav() {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $css_dir = $upload_dir['basedir'] . '/css-manager/';
    $css_files = glob($css_dir . '*.css');
    $current_file = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : '';

    ?>
    <div id="templateside">
        <h2 id="css-files-label">CSS Files</h2>
        <ul role="tree" aria-labelledby="css-files-label">
            <li role="treeitem" tabindex="-1" aria-expanded="true" aria-level="1" aria-posinset="1" aria-setsize="1">
                <ul role="group">
                    <?php foreach ($css_files as $file) : ?>
                        <?php 
                        $file_name = basename($file);
                        // Skip auto-generated minified versions (.min.css)
                        if (strpos($file_name, '.min.css') !== false) {
                            continue;
                        }
                        $is_current = $file_name === $current_file;
                        ?>
                        <li role="none" class="<?php echo $is_current ? 'active' : ''; ?>">
                            <a role="treeitem" tabindex="-1" href="admin.php?page=css_manager_edit&file=<?php echo urlencode($file_name); ?>" aria-level="2">
                                <?php echo esc_html($file_name); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        </ul>
    </div>
    <?php
}

// Register the CSS code editor page and load sidebar
function css_manager_edit_page() {
    if (isset($_POST['save-css'])) {
        $css_file = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : '';
        $css_content = isset($_POST['css-content']) ? wp_unslash($_POST['css-content']) : '';

        if ($css_file && $css_content !== '') {
            $upload_dir = wp_upload_dir();
            $css_dir = $upload_dir['basedir'] . '/css-manager/';
            $file_path = $css_dir . $css_file;
            if (file_exists($file_path)) {
                if (file_put_contents($file_path, $css_content) !== false) {
                    echo '<div class="updated"><p>File saved successfully.</p></div>';
                } else {
                    echo '<div class="error"><p>Failed to save the file. Please check file permissions.</p></div>';
                }
            } else {
                echo '<div class="error"><p>File not found.</p></div>';
            }
        } else {
            echo '<div class="error"><p>Invalid file or content.</p></div>';
        }
    }

    $css_file = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : '';

    if ($css_file) {
        $upload_dir = wp_upload_dir();
        $css_dir = $upload_dir['basedir'] . '/css-manager/';
        $file_path = $css_dir . $css_file;
        if (file_exists($file_path)) {
            $content = file_get_contents($file_path);
        } else {
            $content = 'File not found at path: ' . esc_html($file_path);
        }

        ?>
        <div class="wrap">
			<p><a href="/wp-admin/admin.php?page=css-manager" class="button">All CSS Files</a></p>
			<h2>Edit CSS File: <?php echo esc_html($css_file) ?> </h2>
			<div class="css-manager-editor-wrap">
				<div id="css-manager-editor">
					<form method="post" action="">
						<textarea id="css-editor" name="css-content"><?php echo esc_textarea($content) ?></textarea>
						<br/>
						<input type="submit" name="save-css" class="button button-primary" value="Save Changes">
					</form>
				</div>
				<div id="css-manager-sidebar">
					<?php css_manager_sidebar_nav(); ?>
				</div>
			</div>
        </div>
        <style>
			* {
				box-sizing: border-box;
			}
			.css-manager-editor-wrap {
				display: grid;
				grid-template-columns: 80% 20%;
			}
            #templateside {
                background: #f6f7f7;
				float: none;
				width: 100%;
            }
			#templateside h2 {
				border: 1px solid #dcdcde;
				padding: 13px;
				border-left: none;
			}
			#templateside .active a {
				border-left: 4px solid #72aee6;
				background: white;
				padding: 5px 9px !important;
				box-shadow: 0 0 1px;
			}
        </style>
        <?php

        // Enqueue CodeMirror from CDN
        wp_enqueue_script('codemirror', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.js', array(), null, true);
        wp_enqueue_script('codemirror-css', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/css/css.min.js', array('codemirror'), null, true);
        wp_enqueue_style('codemirror-style', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.css');

        // Inline script to initialize CodeMirror - add it in admin_footer to ensure proper order
        add_action('admin_footer', 'initialize_codemirror_editor');
    } else {
        echo '<p>No file specified to edit.</p>';
    }
}

// Separate function for CodeMirror initialization
function initialize_codemirror_editor() {
    ?>
    <style>
        /* Custom height for CodeMirror editor */
        .CodeMirror {
            min-height: 300px;
			height: calc(100vh - 200px) !important;
			max-height: 900px;
        }
    </style>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            if (typeof CodeMirror !== 'undefined') {
                // Initialize CodeMirror with custom settings
                var editor = CodeMirror.fromTextArea(document.getElementById("css-editor"), {
                    lineNumbers: true,
                    mode: "text/css",
                    theme: "default",
                    lineWrapping: true, // Enable line wrapping to avoid horizontal scrolling
                    extraKeys: {
                        "Ctrl-F": "findPersistent",
                        "Cmd-F": "findPersistent" // macOS support
                    }
                });

                // Load the search dialog on Ctrl-F or Cmd-F
                editor.on("keydown", function(instance, event) {
                    if ((event.ctrlKey || event.metaKey) && event.key === "f") {
                        event.preventDefault();
                        instance.execCommand("findPersistent");
                    }
                });
            } else {
                console.error("CodeMirror is not defined. Please check script loading.");
            }
        });
    </script>
    <?php
    // Enqueue CodeMirror scripts for search functionality
    wp_enqueue_script('codemirror-search', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/search/search.min.js', array('codemirror'), null, true);
    wp_enqueue_script('codemirror-searchcursor', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/search/searchcursor.min.js', array('codemirror'), null, true);
    wp_enqueue_script('codemirror-dialog', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/dialog/dialog.min.js', array('codemirror'), null, true);
    wp_enqueue_style('codemirror-dialog-style', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/addon/dialog/dialog.min.css');
}

function register_css_manager_edit_page() {
    add_submenu_page(
        'css_manager',
        'Edit CSS File',
        'Edit CSS File',
        'manage_options',
        'css_manager_edit',
        'css_manager_edit_page'
    );
}
add_action('admin_menu', 'register_css_manager_edit_page');

// Enqueue active CSS files based on their enqueue_location with priority support
function css_manager_enqueue_active_files() {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $css_dir = $upload_dir['basedir'] . '/css-manager/';
    $table_name = $wpdb->prefix . 'css_manager';

    // Retrieve the list of active files from the database and order by priority
    $active_files = $wpdb->get_results("SELECT id, file_name, enqueue_location, priority FROM $table_name WHERE active = 1 ORDER BY priority ASC");

    foreach ($active_files as $file) {
        $file_name = $file->file_name;
        $file_path = $css_dir . $file_name;
        if (file_exists($file_path)) {
            $file_url = $upload_dir['baseurl'] . '/css-manager/' . $file_name;
            $version = filemtime($file_path); // Cache busting

            // Determine where to enqueue the CSS file based on the enqueue_location value
            switch ($file->enqueue_location) {
                case 'everywhere':
                    wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
                    break;

                case 'admin':
                    if (is_admin()) {
                        wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
                    }
                    break;

                case 'pages':
                    if (is_page()) {
                        wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
                    }
                    break;

                case 'posts':
                    if (is_singular('post')) { // Specifically check for single posts of type 'post'
                        wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
                    }
                    break;

                case 'archives':
                    if (is_archive()) {
                        wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
                    }
                    break;

                case 'homepage':
                    if (is_front_page()) {
                        wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
                    }
                    break;

                case 'specific':
                    // Retrieve the post IDs that were saved for this CSS
                    $specific_ids = get_post_meta($file->id, '_specific_pages_posts', true);
                    $specific_ids_array = array_map('trim', explode(',', $specific_ids));
                    if (is_singular() && in_array(get_the_ID(), $specific_ids_array)) {
                        wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
                    }
                    break;

                case 'post_type':
                    // Retrieve the post type saved for this CSS
                    $selected_post_type = get_post_meta($file->id, '_custom_post_type', true);
                    if ($selected_post_type && is_singular($selected_post_type)) {
                        wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
                    }
                    break;

                default:
                    // If no matching location, don't enqueue
                    break;
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'css_manager_enqueue_active_files');

// Enqueue admin-specific CSS if required
function css_manager_enqueue_admin_files() {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $css_dir = $upload_dir['basedir'] . '/css-manager/';
    $table_name = $wpdb->prefix . 'css_manager';

    // Retrieve the list of active files from the database for admin use
    $active_files = $wpdb->get_results("SELECT file_name, enqueue_location FROM $table_name WHERE active = 1 AND enqueue_location = 'admin'");

    foreach ($active_files as $file) {
        $file_name = $file->file_name;
        $file_path = $css_dir . $file_name;
        if (file_exists($file_path)) {
            $file_url = $upload_dir['baseurl'] . '/css-manager/' . $file_name;
            $version = filemtime($file_path); // Cache busting

            if (is_admin()) {
                wp_enqueue_style(sanitize_title($file_name), $file_url, [], $version);
            }
        }
    }
}
add_action('admin_enqueue_scripts', 'css_manager_enqueue_admin_files');
