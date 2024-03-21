<?php

namespace petruchek;

class HelpfulArticle
{
    private static ?HelpfulArticle $instance = null;
    private \wpdb $wpdb;

    private string $tableName;
    private const AJAX_ACTION_NAME = 'helpful_article_vote';
    private const META_FIELD = 'helpful_article_votes';

    private function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableName = $this->wpdb->prefix . 'helpful_article';

        add_filter('the_content', [ $this, 'filterTheContent' ]);

        register_activation_hook(__FILE__, [ $this, 'onActivation' ]);

        add_action('wp_ajax_' . self::AJAX_ACTION_NAME, [ $this, 'ajaxVote' ]);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_NAME, [ $this, 'ajaxVote' ]);

        add_action('add_meta_boxes', [ $this, 'addMetaboxes' ]);
        add_action('init', [ $this, 'onInit' ]);
    }

    public static function getInstance(): ?HelpfulArticle
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function generateNonce($postID)
    {
        return wp_create_nonce('helpful_article_vote_nonce_' . $postID);
    }

    private function verifyNonce($postID, $nonce): bool
    {
        return $nonce === $this->generateNonce($postID);
    }

    public function filterTheContent($content): string
    {
        global $post;

        if (is_single() && !is_admin()) {
            $nonce = $this->generateNonce($post->ID);
            $ajaxURL = admin_url('admin-ajax.php');
            $markup = '<div class="helpful-article-container helpful-article-before-vote" data-post-id="' . $post->ID . '" data-nonce="' . esc_attr($nonce) . '" data-ajax-url="' . esc_attr($ajaxURL) . '" data-ajax-action="' . esc_attr(self::AJAX_ACTION_NAME) . '">';
            $markup .= '<div class="helpful-article-question">' . __("Was this article helpful?", 'helpful-article') . '</div>';
            $markup .= '<div class="helpful-article-answers">';
            $markup .= '<div class="helpful-article-answer"><btn class="helpful-article-vote-yes">' . __("Yes", 'helpful-article') . '</btn></div>';
            $markup .= '<div class="helpful-article-answer"><btn class="helpful-article-vote-no">' . __("No", 'helpful-article') . '</btn></div>';
            $markup .= '</div>';
            $markup .= '</div>';
            $content .= $markup;

            $markup  = '<div class="helpful-article-container helpful-article-after-vote" data-post-id="' . $post->ID . '">';
            $markup .= '<div class="helpful-article-question"></div>';
            $markup .= '<div class="helpful-article-answers">';
            $markup .= '<div class="helpful-article-answer"><btn class="helpful-article-voted-yes" data-vote="1">AA%</btn></div>';
            $markup .= '<div class="helpful-article-answer"><btn class="helpful-article-voted-no"  data-vote="0">BB%</btn></div>';
            $markup .= '</div>';
            $markup .= '</div>';
            $content .= $markup;

            wp_enqueue_style('helpful-article-styles', plugin_dir_url(__FILE__) . 'assets/css/helpful-article.css');
            wp_enqueue_script('helpful-article-script', plugin_dir_url(__FILE__) . 'assets/js/helpful-article.js');
        }

        return $content;
    }

    public function ajaxVote()
    {
        $postID = intval($_POST['post_id'] ?? 0);
        $nonce = $_POST['nonce'] ?? '';
        $vote = intval($_POST['vote'] ?? 0);
        if (!$postID || !$nonce || !$this->verifyNonce($postID, $nonce)) {
            wp_send_json_error(__("Invalid parameters", 'helpful-article'), 400);
        }
        if (!in_array($vote, [0,1])) {
            wp_send_json_error(__("Invalid vote", 'helpful-article'), 400);
        }

        $response = [
            'activate' => $vote,
            'message'  => __("You have already voted.", 'helpful-article'),
            'votes-1'  => 'N/A',
            'votes-0'  => 'N/A',
        ];

        $fp = $this->getUserFingerprint();
        $previousVote = $this->getPreviousVote($postID, $fp);

        if (!$previousVote) {
            $response['message'] = __("Thank you for your vote.", 'helpful-article');
            $this->recordVote($postID, $vote, $fp);
            $this->updatePostMeta($postID);
        }

        $votes = $this->getPostMeta($postID);

        if ($votes && $votes['total']) {
            $yays = round(100 * $votes['yays'] / $votes['total']);
            $response['votes-1'] = sprintf("%d%%", $yays);
            $response['votes-0'] = sprintf("%d%%", 100 - $yays);
        }

        wp_send_json_success($response);
    }

    private function getUserFingerprint()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    private function getPreviousVote(int $postID, string $fingerPrint)
    {
        $query = $this->wpdb->prepare("
            SELECT * 
            FROM $this->tableName 
            WHERE post_id = %d AND user_fp = %s
            ORDER BY created_at DESC
            LIMIT 1", $postID, $fingerPrint);

        return $this->wpdb->get_row($query);
    }

    private function recordVote(int $postID, int $vote, string $fingerPrint)
    {
        $query = $this->wpdb->prepare("INSERT INTO $this->tableName (post_id, user_fp, vote) VALUES (%d, %s, %d)", $postID, $fingerPrint, $vote);
        return $this->wpdb->query($query);
    }

    private function updatePostMeta($postID)
    {
        $query = $this->wpdb->prepare("SELECT COUNT(*) as total,SUM(vote) as yays FROM {$this->tableName} WHERE post_id=%d", $postID);
        $value = $this->wpdb->get_row($query, ARRAY_A) ?? [ 'total' => 0, 'yays' => 0 ];
        return update_post_meta($postID, self::META_FIELD, $value);
    }

    private function getPostMeta(int $postID)
    {
        return get_post_meta($postID, self::META_FIELD, true) ?? [ 'total' => 0, 'yays' => 0 ];
    }

    public function onInit(): void
    {
        load_plugin_textdomain('helpful-article', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function onActivation(): void
    {
        $charsetCollate = $this->wpdb->get_charset_collate();

        $createTableStatement = "CREATE TABLE IF NOT EXISTS $this->tableName (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_fp VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            vote TINYINT DEFAULT 0
        ) $charsetCollate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($createTableStatement);
    }

    public function addMetaboxes()
    {
        add_meta_box(
            'helpful-article-metabox',
            __('Helpful Article', 'helpful-article'),
            [ $this, 'metaboxCallback' ],
            'post',
            'side',
            'default'
        );
    }

    public function metaboxCallback($post)
    {
        $votes = $this->getPostMeta($post->ID);
        if (!$votes || !isset($votes['total']) || !$votes['total']) {
            echo "<i>" . __("No votes so far.", 'helpful-article') . "</i>";
        } else {
            printf(
                "%s <strong>%d</strong><br />%s <strong>%d</strong><br />%s <strong>%d</strong><br />%s <strong>%d%%</strong><br />%s <strong>%d%%</strong>",
                __("Total votes:", 'helpful-article'),
                $votes['total'],
                __("Positive votes:", 'helpful-article'),
                $votes['yays'],
                __("Negative votes:", 'helpful-article'),
                $votes['total'] - $votes['yays'],
                __("Approval rate:", 'helpful-article'),
                round(100 * $votes['yays'] / $votes['total']),
                __("Disapproval rate:", 'helpful-article'),
                100 - round(100 * $votes['yays'] / $votes['total'])
            );
        }
    }
}
