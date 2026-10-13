<?php
/**
 * Plugin Name: Aparat Shortcode Manager
 * Description: Store Aparat embed scripts in the admin panel and use generated shortcodes inside WooCommerce product descriptions or posts.
 * Version: 1.0.1
 * Author: نیما شادمان
 * Text Domain: aparat-shortcode-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CSH_Aparat_Shortcode_Manager
{
    private const OPTION_KEY = 'csh_aparat_shortcode_items';
    private const NONCE_ACTION = 'csh_aparat_shortcode_manager';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_shortcode('aparat_video', [$this, 'render_shortcode']);
        add_shortcode('aparat', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'register_front_styles']);
    }

    public function add_admin_page(): void
    {
        add_menu_page(
            'ویدئوهای آپارات',
            'ویدئوهای آپارات',
            'manage_options',
            'csh-aparat-shortcodes',
            [$this, 'render_admin_page'],
            'dashicons-video-alt3',
            56
        );
    }

    public function handle_admin_actions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['csh_aparat_action']) && !isset($_GET['csh_aparat_action'])) {
            return;
        }

        if (isset($_POST['csh_aparat_action']) && $_POST['csh_aparat_action'] === 'add') {
            check_admin_referer(self::NONCE_ACTION);

            $title = isset($_POST['csh_aparat_title']) ? sanitize_text_field(wp_unslash($_POST['csh_aparat_title'])) : '';
            $script = isset($_POST['csh_aparat_script']) ? wp_unslash($_POST['csh_aparat_script']) : '';
            $video_hash = $this->extract_video_hash($script);

            if (!$video_hash) {
                $this->redirect_with_message('invalid');
            }

            $items = $this->get_items();
            $item_id = (string) time() . wp_rand(100, 999);
            $items[$item_id] = [
                'id' => $item_id,
                'title' => $title ?: 'ویدئوی آپارات',
                'video_hash' => $video_hash,
                'script' => sanitize_textarea_field($script),
                'created_at' => current_time('mysql'),
            ];

            update_option(self::OPTION_KEY, $items, false);
            $this->redirect_with_message('added');
        }

        if (isset($_GET['csh_aparat_action']) && $_GET['csh_aparat_action'] === 'delete') {
            $item_id = isset($_GET['item']) ? sanitize_key(wp_unslash($_GET['item'])) : '';
            check_admin_referer(self::NONCE_ACTION . '_delete_' . $item_id);

            $items = $this->get_items();
            if (isset($items[$item_id])) {
                unset($items[$item_id]);
                update_option(self::OPTION_KEY, $items, false);
            }

            $this->redirect_with_message('deleted');
        }
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $items = $this->get_items();
        $message = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap csh-aparat-admin" dir="rtl">
            <h1>ویدئوهای آپارات</h1>

            <?php if ($message === 'added') : ?>
                <div class="notice notice-success is-dismissible"><p>ویدئو اضافه شد و شورت‌کد آماده است.</p></div>
            <?php elseif ($message === 'deleted') : ?>
                <div class="notice notice-success is-dismissible"><p>ویدئو حذف شد.</p></div>
            <?php elseif ($message === 'invalid') : ?>
                <div class="notice notice-error is-dismissible"><p>کد وارد شده معتبر نیست. لطفاً کد embed آپارات یا لینک ویدئوی آپارات را وارد کنید.</p></div>
            <?php endif; ?>

            <div class="card" style="max-width: 980px;">
                <h2>افزودن ویدئوی جدید</h2>
                <form method="post" action="">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="csh_aparat_action" value="add">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="csh_aparat_title">عنوان</label></th>
                            <td>
                                <input type="text" class="regular-text" id="csh_aparat_title" name="csh_aparat_title" placeholder="مثلاً ویدئوی کلمن لایف کمپ">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="csh_aparat_script">کد آپارات</label></th>
                            <td>
                                <textarea id="csh_aparat_script" name="csh_aparat_script" class="large-text code" rows="7" required placeholder='<div id="81483435670"><script src="https://www.aparat.com/embed/mwtht5k?data[rnddiv]=81483435670&data[responsive]=yes"></script></div>'></textarea>
                                <p class="description">کد اسکریپت آپارات، iframe آپارات، یا لینک ویدئو را اینجا بگذارید.</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('ساخت شورت‌کد'); ?>
                </form>
            </div>

            <h2>لیست ویدئوها</h2>
            <table class="widefat striped" style="max-width: 980px;">
                <thead>
                    <tr>
                        <th>عنوان</th>
                        <th>شناسه ویدئو</th>
                        <th>شورت‌کد</th>
                        <th>تاریخ</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$items) : ?>
                        <tr><td colspan="5">هنوز ویدئویی ثبت نشده است.</td></tr>
                    <?php else : ?>
                        <?php foreach (array_reverse($items, true) as $item) : ?>
                            <?php $shortcode = '[aparat_video id="' . esc_attr($item['id']) . '"]'; ?>
                            <tr>
                                <td><?php echo esc_html($item['title']); ?></td>
                                <td><code><?php echo esc_html($item['video_hash']); ?></code></td>
                                <td>
                                    <input type="text" class="regular-text code" readonly value="<?php echo esc_attr($shortcode); ?>" onclick="this.select();">
                                </td>
                                <td><?php echo esc_html($item['created_at']); ?></td>
                                <td>
                                    <a class="button button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=csh-aparat-shortcodes&csh_aparat_action=delete&item=' . rawurlencode($item['id'])), self::NONCE_ACTION . '_delete_' . $item['id'])); ?>" onclick="return confirm('این ویدئو حذف شود؟');">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'id' => '',
        ], $atts, 'aparat_video');

        $item_id = sanitize_key((string) $atts['id']);
        if (!$item_id) {
            return '';
        }

        $items = $this->get_items();
        if (!isset($items[$item_id]['video_hash'])) {
            return '';
        }

        $video_hash = sanitize_text_field($items[$item_id]['video_hash']);
        $src = 'https://www.aparat.com/video/video/embed/videohash/' . rawurlencode($video_hash) . '/vt/frame';

        wp_enqueue_style('csh-aparat-shortcode-manager');

        return sprintf(
            '<div class="csh-aparat-video-wrap"><div class="csh-aparat-video"><iframe src="%s" loading="lazy" allowfullscreen webkitallowfullscreen mozallowfullscreen></iframe></div></div>',
            esc_url($src)
        );
    }

    public function register_front_styles(): void
    {
        wp_register_style('csh-aparat-shortcode-manager', false, [], '1.0.1');
        wp_add_inline_style(
            'csh-aparat-shortcode-manager',
            '.csh-aparat-video-wrap{display:block;clear:both;width:100%;max-width:100%;margin:24px 0;line-height:0}.csh-aparat-video{position:relative;display:block;width:100%;max-width:100%;height:0;padding-bottom:56.25%;overflow:hidden;background:#000}.csh-aparat-video iframe{position:absolute;top:0;right:0;bottom:0;left:0;width:100%!important;height:100%!important;max-width:100%;border:0;display:block;margin:0;padding:0}'
        );
    }

    private function get_items(): array
    {
        $items = get_option(self::OPTION_KEY, []);
        return is_array($items) ? $items : [];
    }

    private function extract_video_hash(string $input): string
    {
        $input = trim(html_entity_decode($input));

        $patterns = [
            '~aparat\.com/embed/([A-Za-z0-9_-]+)~i',
            '~videohash/([A-Za-z0-9_-]+)~i',
            '~aparat\.com/v/([A-Za-z0-9_-]+)~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                return sanitize_text_field($matches[1]);
            }
        }

        if (preg_match('~^[A-Za-z0-9_-]{4,}$~', $input)) {
            return sanitize_text_field($input);
        }

        return '';
    }

    private function redirect_with_message(string $message): void
    {
        wp_safe_redirect(admin_url('admin.php?page=csh-aparat-shortcodes&message=' . rawurlencode($message)));
        exit;
    }
}

new CSH_Aparat_Shortcode_Manager();
