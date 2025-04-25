<?php
/*
Plugin Name: WP File Integrity Checker
Description: Проверяет целостность файлов WordPress и выявляет изменения.
Version: 1.3
Author: Andrew Arutunyan & Grok
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit;
}

class WP_File_Integrity_Checker {
    private $wp_version;
    private $checksums_url = 'https://api.wordpress.org/core/checksums/1.0/?version=';
    private $option_name = 'wp_file_integrity_settings';
    private $original_checksums = [];

    public function __construct() {
        $this->wp_version = get_bloginfo('version');
        error_log('WP Version Detected: ' . $this->wp_version);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_wp_file_integrity_scan', [$this, 'ajax_scan']);
        add_action('wp_ajax_wp_file_integrity_progress', [$this, 'ajax_progress']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'File Integrity Checker',
            'File Integrity',
            'manage_options',
            'wp-file-integrity',
            [$this, 'admin_page']
        );
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);
    }

    public function sanitize_settings($input) {
        return [
            'checksum_source' => in_array($input['checksum_source'], ['local', 'online']) ? $input['checksum_source'] : 'online'
        ];
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_wp-file-integrity') {
            return;
        }
        wp_enqueue_script('wp-file-integrity', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], '1.3', true);
        wp_enqueue_style('wp-file-integrity', plugin_dir_url(__FILE__) . 'style.css', [], '1.3');
        wp_localize_script('wp-file-integrity', 'wpFileIntegrity', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_file_integrity_scan')
        ]);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ запрещён.');
        }
        $settings = get_option($this->option_name, ['checksum_source' => 'online']);
        ?>
        <div class="wrap">
            <h1>WP File Integrity Checker</h1>
            <p>Проверяет файлы WordPress версии <?php echo esc_html($this->wp_version); ?>.</p>
            <form method="post" action="options.php">
                <?php settings_fields($this->option_name); ?>
                <h2>Настройки</h2>
                <label><input type="radio" name="<?php echo esc_attr($this->option_name); ?>[checksum_source]" value="online" <?php checked($settings['checksum_source'], 'online'); ?>> Использовать онлайн API</label><br>
                <label><input type="radio" name="<?php echo esc_attr($this->option_name); ?>[checksum_source]" value="local" <?php checked($settings['checksum_source'], 'local'); ?>> Использовать локальный checksums.json</label><br>
                <?php submit_button('Сохранить настройки'); ?>
            </form>
            <h2>Сканирование</h2>
            <button id="scan-files" class="button button-primary">Сканировать файлы</button>
            <div id="scan-progress" style="display: none;">
                <progress id="progress-bar" value="0" max="100"></progress>
                <span id="progress-text">0%</span>
            </div>
            <div id="scan-results"></div>
        </div>
        <?php
    }

    private function fetch_checksums($source) {
        if ($source === 'local') {
            $checksums_file = plugin_dir_path(__FILE__) . 'checksums.json';
            if (file_exists($checksums_file)) {
                $this->original_checksums = json_decode(file_get_contents($checksums_file), true);
                error_log('Loaded local checksums: ' . count($this->original_checksums) . ' files');
                return true;
            }
            error_log('Local checksums file not found');
            return false;
        }

        $response = wp_remote_get($this->checksums_url . $this->wp_version);
        if (is_wp_error($response)) {
            error_log('Checksum API Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        error_log('Checksum API Response: ' . substr($body, 0, 1000));
        $data = json_decode($body, true);

        if (isset($data['checksums'][$this->wp_version]) && is_array($data['checksums'][$this->wp_version])) {
            $this->original_checksums = $data['checksums'][$this->wp_version];
            error_log('Checksums Loaded: ' . count($this->original_checksums) . ' files');
            return true;
        }

        error_log('No checksums found for version ' . $this->wp_version);
        return false;
    }

    public function ajax_scan() {
        check_ajax_referer('wp_file_integrity_scan', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещён.');
        }

        $settings = get_option($this->option_name, ['checksum_source' => 'online']);
        $source = $settings['checksum_source'];

        if (!$this->fetch_checksums($source)) {
            wp_send_json_error('Не удалось загрузить контрольные суммы.');
        }

        $results = $this->scan_files();
        $html = $this->render_results($results);
        wp_send_json_success(['html' => $html]);
    }

    public function ajax_progress() {
        check_ajax_referer('wp_file_integrity_scan', 'nonce');
        $progress = get_transient('wp_file_integrity_progress') ?: 0;
        wp_send_json_success(['progress' => $progress]);
    }

    private function scan_files() {
        $results = [
            'modified' => [],
            'missing' => [],
            'unknown' => []
        ];

        $wp_path = ABSPATH;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wp_path, RecursiveDirectoryIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file;
            }
        }

        $total_files = count($files);
        $processed = 0;

        foreach ($files as $file) {
            $relative_path = str_replace($wp_path, '', $file->getPathname());
            $relative_path = ltrim($relative_path, '/\\');
            error_log('Processing file: ' . $relative_path);

            if (strpos($relative_path, 'wp-content/') === 0) {
                continue;
            }

            $current_hash = md5_file($file->getPathname());

            if (isset($this->original_checksums[$relative_path])) {
                if ($current_hash !== $this->original_checksums[$relative_path]) {
                    $results['modified'][] = $relative_path;
                    error_log('Modified file: ' . $relative_path);
                } else {
                    error_log('File OK: ' . $relative_path);
                }
            } else {
                $results['unknown'][] = $relative_path;
                error_log('Unknown file: ' . $relative_path);
            }

            $processed++;
            if ($processed % 10 === 0 || $processed === $total_files) {
                $progress = ($processed / $total_files) * 100;
                set_transient('wp_file_integrity_progress', $progress, 60);
            }
        }

        foreach ($this->original_checksums as $file => $hash) {
            if (!file_exists($wp_path . $file)) {
                $results['missing'][] = $file;
                error_log('Missing file: ' . $file);
            }
        }

        return $results;
    }

    private function render_results($results) {
        ob_start();
        ?>
        <h2>Результаты сканирования</h2>
        <?php if (empty($results['modified']) && empty($results['missing']) && empty($results['unknown'])): ?>
            <p class="notice notice-success">Изменений не обнаружено.</p>
        <?php else: ?>
            <?php if (!empty($results['modified'])): ?>
                <h3>Изменённые файлы (<?php echo count($results['modified']); ?>)</h3>
                <ul class="file-list" data-type="modified">
                    <?php foreach (array_slice($results['modified'], 0, 10) as $file): ?>
                        <li><?php echo esc_html($file); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($results['modified']) > 10): ?>
                        <li><a href="#" class="show-more" data-type="modified">Показать все (<?php echo count($results['modified']); ?>)</a></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($results['missing'])): ?>
                <h3>Отсутствующие файлы (<?php echo count($results['missing']); ?>)</h3>
                <ul class="file-list" data-type="missing">
                    <?php foreach (array_slice($results['missing'], 0, 10) as $file): ?>
                        <li><?php echo esc_html($file); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($results['missing']) > 10): ?>
                        <li><a href="#" class="show-more" data-type="missing">Показать все (<?php echo count($results['missing']); ?>)</a></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($results['unknown'])): ?>
                <h3>Неизвестные файлы (<?php echo count($results['unknown']); ?>)</h3>
                <ul class="file-list" data-type="unknown">
                    <?php foreach (array_slice($results['unknown'], 0, 10) as $file): ?>
                        <li><?php echo esc_html($file); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($results['unknown']) > 10): ?>
                        <li><a href="#" class="show-more" data-type="unknown">Показать все (<?php echo count($results['unknown']); ?>)</a></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}

new WP_File_Integrity_Checker();