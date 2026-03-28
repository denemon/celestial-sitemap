<?php
/**
 * Admin robots.txt editor template.
 *
 * @var \CelestialSitemap\Core\Options $opts
 * @var bool                           $physicalFileExists
 * @var string[]                       $competingPlugins
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap cel-wrap">
    <h1><?php esc_html_e('Celestial Sitemap — robots.txt Editor', 'celestial-sitemap'); ?></h1>

    <div id="cel-robots-notice" class="notice" style="display:none;"></div>

    <?php if ($physicalFileExists) : ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Physical robots.txt detected', 'celestial-sitemap'); ?></strong><br>
                <?php
                printf(
                    /* translators: %s: file path */
                    esc_html__('A physical robots.txt file exists at %s. WordPress (and this editor) cannot override a physical file. Delete or rename the file to use this editor.', 'celestial-sitemap'),
                    '<code>' . esc_html(ABSPATH . 'robots.txt') . '</code>'
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($competingPlugins !== []) : ?>
        <div class="notice notice-warning">
            <p>
                <?php
                printf(
                    /* translators: %s: comma-separated list of plugin names */
                    esc_html__('The following plugins may also modify robots.txt output: %s. This could cause conflicts with your custom content.', 'celestial-sitemap'),
                    '<strong>' . esc_html(implode(', ', $competingPlugins)) . '</strong>'
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <form id="cel-robots-txt-form">

        <div class="cel-card">
            <h2><?php esc_html_e('Custom robots.txt', 'celestial-sitemap'); ?></h2>
            <p class="description">
                <?php esc_html_e('When enabled, this content replaces the WordPress-generated robots.txt. The sitemap URL is automatically appended if not already present.', 'celestial-sitemap'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Enable Custom robots.txt', 'celestial-sitemap'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="cel_robots_txt_enabled" value="1" <?php checked($opts->robotsTxtEnabled()); ?> />
                            <?php esc_html_e('Use custom robots.txt content instead of WordPress default', 'celestial-sitemap'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="cel_robots_txt_content"><?php esc_html_e('Content', 'celestial-sitemap'); ?></label>
                    </th>
                    <td>
                        <textarea
                            id="cel_robots_txt_content"
                            name="cel_robots_txt_content"
                            class="large-text code cel-robots-textarea"
                            rows="15"
                            placeholder="<?php echo esc_attr("User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: " . home_url('/cel-sitemap.xml')); ?>"
                        ><?php echo esc_textarea($opts->robotsTxtContent()); ?></textarea>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: maximum kilobytes */
                                esc_html__('Maximum %d KB. One directive per line. The sitemap URL (%s) is appended automatically if not included.', 'celestial-sitemap'),
                                64,
                                '<code>' . esc_html(home_url('/cel-sitemap.xml')) . '</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cel-card">
            <h2><?php esc_html_e('Preview', 'celestial-sitemap'); ?></h2>
            <p>
                <a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank" class="button">
                    <?php esc_html_e('View Current robots.txt', 'celestial-sitemap'); ?>
                </a>
            </p>
            <p class="description">
                <?php esc_html_e('Opens the live robots.txt output in a new tab. Save changes first to see updates.', 'celestial-sitemap'); ?>
            </p>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary" id="cel-save-robots-txt">
                <?php esc_html_e('Save robots.txt Settings', 'celestial-sitemap'); ?>
            </button>
        </p>
    </form>
</div>
