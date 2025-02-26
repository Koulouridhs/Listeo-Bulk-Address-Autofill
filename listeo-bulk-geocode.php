<?php
/**
 * Plugin Name: Listeo Bulk Address Autofill
 * Description: A professional tool for managing and autofilling addresses in Listeo listings. This plugin allows administrators to efficiently update the '_address' field of listings based on their '_place_id' using the Google Places API. Features include a customizable settings page for API key and pagination preferences, advanced filtering by Place ID presence and location, and an elegant, user-friendly interface with enhanced pagination. Ideal for maintaining accurate geolocation data in bulk.
 * Version: 1.11
 * Author: George Koulouridhs
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// Add admin menu with settings
add_action('admin_menu', 'lbgt_add_admin_menu');
function lbgt_add_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Listeo Bulk Address Autofill',
        'Listeo Bulk Address Autofill',
        'manage_options',
        'listeo-bulk-address-autofill',
        'lbgt_autofill_admin_page'
    );
    add_submenu_page(
        'tools.php',
        'Listeo Autofill Settings',
        'Autofill Settings',
        'manage_options',
        'listeo-autofill-settings',
        'lbgt_settings_page'
    );
}

// Settings page
function lbgt_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Save settings
    if (isset($_POST['lbgt_save_settings']) && check_admin_referer('lbgt_save_settings_action', 'lbgt_settings_nonce')) {
        update_option('lbgt_api_key', sanitize_text_field($_POST['lbgt_api_key']));
        update_option('lbgt_listings_per_page', max(1, intval($_POST['lbgt_listings_per_page'])));
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
    }

    $api_key = get_option('lbgt_api_key', '');
    $listings_per_page = get_option('lbgt_listings_per_page', 50);
    ?>
    <div class="wrap">
        <h1>Listeo Autofill Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('lbgt_save_settings_action', 'lbgt_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="lbgt_api_key">Google Places API Key</label></th>
                    <td><input type="text" name="lbgt_api_key" id="lbgt_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><label for="lbgt_listings_per_page">Listings Per Page</label></th>
                    <td><input type="number" name="lbgt_listings_per_page" id="lbgt_listings_per_page" value="<?php echo esc_attr($listings_per_page); ?>" min="1" class="small-text" /></td>
                </tr>
            </table>
            <input type="hidden" name="lbgt_save_settings" value="1" />
            <input type="submit" class="button button-primary" value="Save Settings" />
        </form>
    </div>
    <?php
}

// Extract location from _address
function lbgt_extract_location($address) {
    if (empty($address)) {
        return 'N/A';
    }

    $parts = array_map('trim', explode(',', $address));
    return count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];
}

// Admin page with filters and pagination
function lbgt_autofill_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $processed_listings = [];
    $errors = [];
    $per_page = get_option('lbgt_listings_per_page', 50);
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $place_id_filter = isset($_GET['place_id_filter']) ? $_GET['place_id_filter'] : 'all';
    $location_filter = isset($_GET['location_filter']) ? sanitize_text_field($_GET['location_filter']) : '';

    if (isset($_POST['lbgt_action']) && $_POST['lbgt_action'] === 'autofill_addresses' && !empty($_POST['listing_ids'])) {
        check_admin_referer('lbgt_autofill_action', 'lbgt_autofill_nonce');
        $results = lbgt_autofill_addresses($_POST['listing_ids']);
        $processed_listings = $results['success'];
        $errors = $results['errors'];
        echo '<div class="notice notice-success is-dismissible"><p>Selected addresses autofilled successfully!</p></div>';
    }

    // Build query args with filters
    $args = [
        'post_type'      => 'listing',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'post_status'    => 'any',
    ];

    // Place ID filter
    if ($place_id_filter === 'with') {
        $args['meta_query'] = [
            [
                'key'     => '_place_id',
                'compare' => 'EXISTS',
            ],
        ];
    } elseif ($place_id_filter === 'without') {
        $args['meta_query'] = [
            [
                'key'     => '_place_id',
                'compare' => 'NOT EXISTS',
            ],
        ];
    }

    // Location filter (basic search in _address)
    if (!empty($location_filter)) {
        $args['meta_query'] = isset($args['meta_query']) ? array_merge($args['meta_query'], [
            [
                'key'     => '_address',
                'value'   => $location_filter,
                'compare' => 'LIKE',
            ],
        ]) : [
            [
                'key'     => '_address',
                'value'   => $location_filter,
                'compare' => 'LIKE',
            ],
        ];
    }

    $query = new WP_Query($args);
    $listings = $query->posts;
    $total_listings = $query->found_posts;
    $total_pages = ceil($total_listings / $per_page);

    ?>
    <div class="wrap">
        <h1>Listeo Bulk Address Autofill</h1>
        <p>Select listings to autofill their _address field based on _place_id using Google Places API. Filter by Place ID or location, and navigate with enhanced pagination.</p>

        <!-- Filters -->
        <form method="get" action="">
            <input type="hidden" name="page" value="listeo-bulk-address-autofill" />
            <label for="place_id_filter">Filter by Place ID:</label>
            <select name="place_id_filter" id="place_id_filter" onchange="this.form.submit()">
                <option value="all" <?php selected($place_id_filter, 'all'); ?>>All</option>
                <option value="with" <?php selected($place_id_filter, 'with'); ?>>With Place ID</option>
                <option value="without" <?php selected($place_id_filter, 'without'); ?>>Without Place ID</option>
            </select>

            <label for="location_filter" style="margin-left: 20px;">Filter by Location:</label>
            <input type="text" name="location_filter" id="location_filter" value="<?php echo esc_attr($location_filter); ?>" placeholder="e.g., Corfu" />
            <input type="submit" class="button" value="Apply Filters" />
        </form>

        <!-- Listings Form -->
        <form method="post" action="">
            <?php wp_nonce_field('lbgt_autofill_action', 'lbgt_autofill_nonce'); ?>
            <input type="hidden" name="lbgt_action" value="autofill_addresses" />
            
            <table class="widefat striped" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all" /></th>
                        <th>Post ID</th>
                        <th>Title</th>
                        <th>Current Address (Location)</th>
                        <th>Place ID</th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                        <th>URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $listing): ?>
                        <?php
                        $title = get_the_title($listing->ID);
                        $address = get_post_meta($listing->ID, '_address', true);
                        $location = lbgt_extract_location($address);
                        $place_id = get_post_meta($listing->ID, '_place_id', true);
                        $lat = get_post_meta($listing->ID, '_geolocation_lat', true);
                        $lng = get_post_meta($listing->ID, '_geolocation_long', true);
                        ?>
                        <tr>
                            <td><input type="checkbox" name="listing_ids[]" value="<?php echo esc_attr($listing->ID); ?>" /></td>
                            <td><?php echo esc_html($listing->ID); ?></td>
                            <td><?php echo esc_html($title); ?></td>
                            <td><?php echo esc_html($location); ?></td>
                            <td><?php echo esc_html($place_id ?: 'N/A'); ?></td>
                            <td><?php echo esc_html($lat ?: 'N/A'); ?></td>
                            <td><?php echo esc_html($lng ?: 'N/A'); ?></td>
                            <td><a href="<?php echo esc_url(get_permalink($listing->ID)); ?>" target="_blank">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <input type="submit" class="button button-primary" value="Autofill Selected Addresses" style="margin-top: 20px;" />
        </form>

        <!-- Enhanced Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav" style="margin-top: 20px;">
                <div class="tablenav-pages" style="display: flex; align-items: center; gap: 10px;">
                    <?php
                    $pagination_args = [
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '<span class="dashicons dashicons-arrow-left-alt2"></span> Previous',
                        'next_text' => 'Next <span class="dashicons dashicons-arrow-right-alt2"></span>',
                        'type'      => 'array',
                    ];
                    $links = paginate_links($pagination_args);
                    if ($links) {
                        foreach ($links as $link) {
                            echo '<span class="button" style="margin: 0 5px; ' . (strpos($link, 'current') !== false ? 'background: #0073aa; color: white;' : '') . '">' . $link . '</span>';
                        }
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Processed Listings and Errors -->
        <?php if (!empty($processed_listings) || !empty($errors)): ?>
            <hr style="margin: 20px 0;" />
            <h2>Processed Listings</h2>
            <?php if (!empty($processed_listings)): ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Post ID</th>
                            <th>New Address</th>
                            <th>Place ID</th>
                            <th>Latitude</th>
                            <th>Longitude</th>
                            <th>Listing URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processed_listings as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item['ID']); ?></td>
                                <td><?php echo esc_html($item['address']); ?></td>
                                <td><?php echo esc_html($item['place_id']); ?></td>
                                <td><?php echo esc_html($item['lat']); ?></td>
                                <td><?php echo esc_html($item['lng']); ?></td>
                                <td><a href="<?php echo esc_url($item['url']); ?>" target="_blank">View Listing</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No addresses autofilled.</p>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <h2>Errors</h2>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

        <style>
            .tablenav-pages .button { padding: 5px 15px; }
            .tablenav-pages .button:hover { background: #0085ba; color: white; }
            .dashicons { vertical-align: middle; }
        </style>
        <script>
            document.getElementById('select-all').addEventListener('change', function() {
                var checkboxes = document.querySelectorAll('input[name="listing_ids[]"]');
                checkboxes.forEach(function(checkbox) {
                    checkbox.checked = this.checked;
                }, this);
            });
        </script>
    </div>
    <?php
}

// Autofill addresses based on _place_id with caching
function lbgt_autofill_addresses($listing_ids) {
    $processed_listings = ['success' => [], 'errors' => []];

    foreach ($listing_ids as $listing_id) {
        $place_id = get_post_meta($listing_id, '_place_id', true);
        if (empty($place_id)) {
            $processed_listings['errors'][] = "Post ID {$listing_id}: No _place_id found.";
            continue;
        }

        $cache_key = 'lbgt_place_' . md5($place_id);
        $cached_result = get_transient($cache_key);
        if ($cached_result !== false) {
            $result = $cached_result;
        } else {
            $result = lbgt_fetch_place_details($place_id);
            if ($result) {
                set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
            }
        }

        if ($result) {
            update_post_meta($listing_id, '_address', $result['formatted_address']);
            update_post_meta($listing_id, '_geolocation_lat', $result['lat']);
            update_post_meta($listing_id, '_geolocation_long', $result['lng']);

            $processed_listings['success'][] = [
                'ID'      => $listing_id,
                'address' => $result['formatted_address'],
                'place_id'=> $place_id,
                'lat'     => $result['lat'],
                'lng'     => $result['lng'],
                'url'     => get_permalink($listing_id),
            ];
        } else {
            $processed_listings['errors'][] = "Post ID {$listing_id}: Failed to fetch details for Place ID '{$place_id}'.";
        }
    }

    return $processed_listings;
}

// Fetch place details using Google Places API
function lbgt_fetch_place_details($place_id) {
    $api_key = get_option('lbgt_api_key', '');
    if (empty($api_key)) {
        error_log("No API key set for Listeo Bulk Address Autofill.");
        return false;
    }

    $details_url = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' 
        . urlencode($place_id) 
        . '&key=' . $api_key;
    $response = wp_remote_get($details_url);

    if (is_wp_error($response)) {
        error_log("Details API error for place_id '$place_id': " . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    error_log("Details response for '$place_id': " . print_r($data, true));

    if ($data['status'] !== 'OK' || empty($data['result'])) {
        error_log("Invalid details response for '$place_id': " . $data['status']);
        return false;
    }

    $result = $data['result'];
    return [
        'formatted_address' => $result['formatted_address'],
        'lat'               => $result['geometry']['location']['lat'],
        'lng'               => $result['geometry']['location']['lng'],
    ];
}
?>
