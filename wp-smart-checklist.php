<?php
/*
Plugin Name: WP Smart Checklist
Description: 投稿の公開チェックリスト管理（進捗表示・フィルター・色分け・設定画面・投稿タイプ対応）
Version: 2.5
Author: masato shibuya(Image-box Co., Ltd.)
*/

// ======================
// チェック項目取得
// ======================
function wpsc_get_items() {
    $saved = get_option('wpsc_items');

    if (!empty($saved)) {
        $items = [];
        foreach ($saved as $i => $label) {
            $items['item_' . $i] = $label;
        }
        return $items;
    }

    return [
        'title' => 'タイトルOK？',
        'thumb' => 'アイキャッチ設定した？',
        'desc'  => 'ディスクリプション書いた？',
        'spell' => '誤字脱字チェックした？'
    ];
}

// ======================
// 投稿タイプ判定
// ======================
function wpsc_is_enabled_post_type($post_type) {
    $types = get_option('wpsc_post_types', ['post']);
    return in_array($post_type, $types);
}

// ======================
// 設定ページ
// ======================
add_action('admin_menu', function() {
    add_options_page('チェックリスト設定', 'チェックリスト', 'manage_options', 'wpsc-settings', 'wpsc_settings_page');
});

function wpsc_settings_page() {

    if (isset($_POST['wpsc_items'])) {
        check_admin_referer('wpsc_settings');

        $items = explode("\n", $_POST['wpsc_items']);
        $items = array_map('sanitize_text_field', $items);
        $items = array_filter($items);

        update_option('wpsc_items', $items);
    }

    if (isset($_POST['wpsc_post_types'])) {
        $types = array_map('sanitize_text_field', $_POST['wpsc_post_types']);
        update_option('wpsc_post_types', $types);
    }

    $items = get_option('wpsc_items', []);
    $text = implode("\n", $items);

    $post_types = get_post_types(['public' => true], 'objects');
    $saved_types = get_option('wpsc_post_types', ['post']);
    ?>
    <div class="wrap">
        <h1>チェックリスト設定</h1>

        <form method="post">
            <?php wp_nonce_field('wpsc_settings'); ?>

            <h2>チェック項目</h2>
            <textarea name="wpsc_items" style="width:100%;height:200px;"><?php echo esc_textarea($text); ?></textarea>

            <h2>対象投稿タイプ</h2>
            <?php foreach ($post_types as $type): ?>
                <label>
                    <input type="checkbox" name="wpsc_post_types[]" value="<?php echo esc_attr($type->name); ?>"
                    <?php checked(in_array($type->name, $saved_types)); ?>>
                    <?php echo esc_html($type->label); ?>
                </label><br>
            <?php endforeach; ?>

            <br>
            <input type="submit" class="button button-primary" value="保存">
        </form>
    </div>
    <?php
}

// ======================
// メタボックス
// ======================
add_action('add_meta_boxes', function() {

    $types = get_option('wpsc_post_types', ['post']);

    foreach ($types as $type) {
        add_meta_box('wpsc_checklist', '公開チェックリスト', 'wpsc_meta_box', $type, 'side');
    }
});

function wpsc_meta_box($post) {

    if (!wpsc_is_enabled_post_type($post->post_type)) return;

    $items = wpsc_get_items();
    $saved = get_post_meta($post->ID, 'wpsc_checklist', true) ?: [];

    wp_nonce_field('wpsc_save', 'wpsc_nonce');

    $count = count($saved);
    $total = count($items);
    $percent = $total ? intval(($count / $total) * 100) : 0;

    echo "<p><strong>進捗：{$percent}%</strong></p>";

    foreach ($items as $key => $label) {
        $checked = isset($saved[$key]) ? 'checked' : '';
        echo "<label><input type='checkbox' name='wpsc_checklist[$key]' $checked> $label</label><br>";
    }
}

// ======================
// 保存
// ======================
add_action('save_post', function($post_id) {

    if (!isset($_POST['wpsc_nonce']) || !wp_verify_nonce($_POST['wpsc_nonce'], 'wpsc_save')) return;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (!current_user_can('edit_post', $post_id)) return;

    $post_type = get_post_type($post_id);
    if (!wpsc_is_enabled_post_type($post_type)) return;

    $data = $_POST['wpsc_checklist'] ?? [];
    $clean = array_map('sanitize_text_field', $data);

    update_post_meta($post_id, 'wpsc_checklist', $clean);
    update_post_meta($post_id, 'wpsc_checklist_count', count($clean));
});

// ======================
// カラム
// ======================
add_filter('manage_posts_columns', function($columns) {
    global $post_type;

    if (wpsc_is_enabled_post_type($post_type)) {
        $columns['wpsc'] = 'チェック状況';
    }

    return $columns;
});

add_action('manage_posts_custom_column', function($column, $post_id) {

    if ($column !== 'wpsc') return;

    $post_type = get_post_type($post_id);
    if (!wpsc_is_enabled_post_type($post_type)) return;

    $count = get_post_meta($post_id, 'wpsc_checklist_count', true);
    $total = count(wpsc_get_items());

    if (!$count) {
        echo '❌ 未チェック';
        return;
    }

    $percent = intval(($count / $total) * 100);

    if ($percent == 100) {
        echo "<span style='color:green;'>✅ {$percent}%</span>";
    } elseif ($percent >= 50) {
        echo "<span style='color:orange;'>⚠️ {$percent}%</span>";
    } else {
        echo "<span style='color:red;'>❌ {$percent}%</span>";
    }

}, 10, 2);

// ======================
// フィルターUI
// ======================
add_action('restrict_manage_posts', function() {

    global $typenow;
    if (!wpsc_is_enabled_post_type($typenow)) return;

    $selected = $_GET['wpsc_filter'] ?? '';
    ?>
    <select name="wpsc_filter">
        <option value="">すべて</option>
        <option value="complete" <?php selected($selected, 'complete'); ?>>完了</option>
        <option value="incomplete" <?php selected($selected, 'incomplete'); ?>>未完了</option>
        <option value="none" <?php selected($selected, 'none'); ?>>未チェック</option>
    </select>
    <?php
});

// ======================
// フィルター処理
// ======================
add_action('pre_get_posts', function($query) {

    if (!is_admin() || !$query->is_main_query()) return;

    global $pagenow, $typenow;
    if ($pagenow !== 'edit.php') return;
    if (!wpsc_is_enabled_post_type($typenow)) return;

    if (empty($_GET['wpsc_filter'])) return;

    $filter = $_GET['wpsc_filter'];
    $total = count(wpsc_get_items());

    if ($filter === 'none') {
        $query->set('meta_query', [
            [
                'key' => 'wpsc_checklist_count',
                'compare' => 'NOT EXISTS'
            ]
        ]);
    }

    if ($filter === 'complete') {
        $query->set('meta_query', [
            [
                'key' => 'wpsc_checklist_count',
                'value' => $total,
                'compare' => '='
            ]
        ]);
    }

    if ($filter === 'incomplete') {
        $query->set('meta_query', [
            [
                'key' => 'wpsc_checklist_count',
                'value' => $total,
                'compare' => '<',
                'type' => 'NUMERIC'
            ]
        ]);
    }
});

// ======================
// 行の色
// ======================
add_filter('post_class', function($classes, $class, $post_id) {

    if (!is_admin()) return $classes;

    $post_type = get_post_type($post_id);
    if (!wpsc_is_enabled_post_type($post_type)) return $classes;

    $count = get_post_meta($post_id, 'wpsc_checklist_count', true);
    $total = count(wpsc_get_items());

    if (!$count) {
        $classes[] = 'wpsc-none';
    } elseif ($count < $total) {
        $classes[] = 'wpsc-incomplete';
    } else {
        $classes[] = 'wpsc-complete';
    }

    return $classes;

}, 10, 3);

// ======================
// CSS
// ======================
add_action('admin_head', function() {
    echo '<style>
        .wpsc-none td { background:#ffe5e5 !important; }
        .wpsc-incomplete td { background:#fff5cc !important; }
        .wpsc-complete td { background:#e5ffe5 !important; }
    </style>';
});

// ======================
// 公開前アラート
// ======================
add_action('admin_footer', function() {
    global $post;
    if (!$post) return;
    if (!wpsc_is_enabled_post_type($post->post_type)) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const publishBtn = document.querySelector('#publish');
        if (!publishBtn) return;

        publishBtn.addEventListener('click', function(e) {
            const checks = document.querySelectorAll('#wpsc_checklist input[type="checkbox"]');
            const checked = document.querySelectorAll('#wpsc_checklist input:checked');

            if (checks.length && checked.length < checks.length) {
                if (!confirm('チェック未完了だけど公開する？')) {
                    e.preventDefault();
                }
            }
        });
    });
    </script>
    <?php
});


require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

$updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/ms13th-cyber/wp-smart-checklist/',
    __FILE__,
    'wp-smart-checklist'
);

$updateChecker->setBranch('main');