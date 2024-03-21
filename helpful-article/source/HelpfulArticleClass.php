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

        register_activation_hook(plugin_dir_path(__FILE__) . 'helpful-article.php', [ $this, 'onActivation' ]);

        add_action('wp_ajax_' . self::AJAX_ACTION_NAME, [ $this, 'ajaxVote' ]);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_NAME, [ $this, 'ajaxVote' ]);

        add_action('add_meta_boxes', [ $this, 'addMetaboxes' ]);
        add_action('init', [ $this, 'onInit' ]);
    }

    /*
     * Singleton implementation
     */
    public static function getInstance(): ?HelpfulArticle
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /*
     * We use it to prevent automatic voting
     */
    private function generateNonce($postID)
    {
        return wp_create_nonce('helpful_article_vote_nonce_' . $postID);
    }

    /*
     * The actual verification of the nonce validity
     */
    private function verifyNonce($postID, $nonce): bool
    {
        return $nonce === $this->generateNonce($postID);
    }

    /*
     * Inject HTML into post contents. Also, enqueue stylesheet and javascript
     */
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

    /*
     * AJAX handler for both logged-in and non-logged-in users
     */
    public function ajaxVote()
    {
        $postID = intval($_POST['post_id'] ?? 0);
        $nonce = $_POST['nonce'] ?? '';
        $vote = intval($_POST['vote'] ?? 0);
        //make sure the submission is not coming from a bot
        if (!$postID || !$nonce || !$this->verifyNonce($postID, $nonce)) {
            wp_send_json_error(__("Invalid parameters", 'helpful-article'), 400);
        }
        //basic validation
        if (!in_array($vote, [0,1])) {
            wp_send_json_error(__("Invalid vote", 'helpful-article'), 400);
        }

        //we will change some fields later
        $response = [
            'activate' => $vote,
            'message'  => __("You have already voted.", 'helpful-article'),
            'votes-1'  => 'N/A',
            'votes-0'  => 'N/A',
        ];

        //fingerprint the user and check if he already voted
        $fp = $this->getUserFingerprint();
        $previousVote = $this->getPreviousVote($postID, $fp);

        //only if this is a new vote - record it and re-calculate the article score
        if (!$previousVote) {
            $response['message'] = __("Thank you for your vote.", 'helpful-article');
            $this->recordVote($postID, $vote, $fp);
            $this->updatePostMeta($postID);
        }

        //fetch article score
        $votes = $this->getPostMeta($postID);

        //calculate the percentage
        if ($votes && $votes['total']) {
            $yays = round(100 * $votes['yays'] / $votes['total']);
            $response['votes-1'] = sprintf("%d%%", $yays);
            $response['votes-0'] = sprintf("%d%%", 100 - $yays);
        }

        wp_send_json_success($response);
    }

    /*
     * For now only this, later we can do some browser fingerprinting
     */
    private function getUserFingerprint()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    /*
     * Can be simplified to return bool, but returning the last vote allows to implement conditional multiple voting
     */
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

    /*
     * Populate 1 row into our table
     */
    private function recordVote(int $postID, int $vote, string $fingerPrint)
    {
        $query = $this->wpdb->prepare("INSERT IGNORE INTO $this->tableName (post_id, user_fp, vote) VALUES (%d, %s, %d)", $postID, $fingerPrint, $vote);
        return $this->wpdb->query($query);
    }

    /*
     * Fetch aggregative votes and store totals + yes votes
     */
    private function updatePostMeta($postID)
    {
        $query = $this->wpdb->prepare("SELECT COUNT(*) as total,SUM(vote) as yays FROM {$this->tableName} WHERE post_id=%d", $postID);
        $value = $this->wpdb->get_row($query, ARRAY_A) ?? [ 'total' => 0, 'yays' => 0 ];
        return update_post_meta($postID, self::META_FIELD, $value);
    }

    /*
     * Just a wrapper + default array
     */
    private function getPostMeta(int $postID)
    {
        return get_post_meta($postID, self::META_FIELD, true) ?? [ 'total' => 0, 'yays' => 0 ];
    }

    /*
     * For i18n/l10n
     */
    public function onInit(): void
    {
        load_plugin_textdomain('helpful-article', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /*
     * We need to remove UNIQUE KEY if we want to allow multiple votes from the same user
     */
    public function onActivation(): void
    {
        $charsetCollate = $this->wpdb->get_charset_collate();

        $createTableStatement = "CREATE TABLE IF NOT EXISTS $this->tableName (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_fp VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            vote TINYINT DEFAULT 0,
            UNIQUE KEY post_user_unique (post_id, user_fp)
        ) $charsetCollate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($createTableStatement);
    }

    /*
     * Adding dashboard metabox
     */
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

    /*
     * Displaying dashboard metabox
     */
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
