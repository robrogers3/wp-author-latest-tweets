<?php
/*
Plugin Name: Author Latest Tweets
Plugin URI: http://www.stratecomm.com
Description: Provide a widget that fetches the latest tweets feed from twitter and display in the sidebar.
Author: stratecomm, vietdt, rrogers,
Author URI: http://www.stratecomm.com
Version: 1.0
License: GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

// register AuthorLatestTweetsWidget
add_action('widgets_init', create_function('', 'return register_widget("AuthorLatestTweetsWidget");'));

/**
 * AuthorLatestTweetsWidget Class
 */
class AuthorLatestTweetsWidget extends WP_Widget {
    /** constructor */
    function AuthorLatestTweetsWidget() {
        /* Widget settings. */
        $widget_ops = array( 'classname' => 'AuthorLatestTweetsWidget', 'description' => __('Widget that shows latest tweets of the author') );

        /* Widget control settings. */
        $control_ops = array('id_base' => 'author-latest-tweets-widget' );

        /* Create the widget. */
        parent::WP_Widget( 'author-latest-tweets-widget', __('Author Latest Tweets'), $widget_ops, $control_ops );
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {
        extract( $args );

        $title = $instance['title'];
        if ( empty($title) ) $title = __( 'Author on Twitter' );
        $tweets = latest_tweets($instance);

        if ($tweets) {
            echo $before_widget;
            echo $before_title;
            echo $title;
            echo $after_title;
            echo $tweets;
            echo $after_widget;
        }
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {
        $instance = $old_instance;
        // validate data
        $instance['title'] = strip_tags($new_instance['title']);
        $instance['show'] = absint($new_instance['show']);
        $instance['show_only_on_author'] = isset($new_instance['show_only_on_author']);
        $instance['show_only_on_single'] = isset($new_instance['show_only_on_single']);
        $instance['updated'] = (string)time();
        
        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {
        /* Set up some default widget settings. */
        $defaults = array( 'title' => '', 'show' => 3, 'show_only_on_author' => false, 'show_only_on_single' => false );
        $instance = wp_parse_args( (array) $instance, $defaults );

        $title = esc_attr($instance['title']);
        $show = absint($instance['show']);
        $show_only_on_author = absint($instance['show_only_on_author']);
        $show_only_on_single = absint($instance['show_only_on_single']);
?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('show'); ?>"><?php _e('Number of tweets to display:'); ?>
            <input class="widefat" id="<?php echo $this->get_field_id('show'); ?>" name="<?php echo $this->get_field_name('show'); ?>" type="text" value="<?php echo $show; ?>" /></label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('show_only_on_author'); ?>">
            <input id="<?php echo $this->get_field_id('show_only_on_author'); ?>" class="checkbox" type="checkbox" name="<?php echo $this->get_field_name('show_only_on_author'); ?>"<?php if ( $show_only_on_author ) echo ' checked="checked"'; ?> /> <?php _e('Show only on author archives') ?></label>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('show_only_on_single'); ?>">
            <input id="<?php echo $this->get_field_id('show_only_on_single'); ?>" class="checkbox" type="checkbox" name="<?php echo $this->get_field_name('show_only_on_single'); ?>"<?php if ( $show_only_on_single ) echo ' checked="checked"'; ?> /> <?php _e('Show only on post view') ?></label>
        </p>
        <p><i><?php _e('Default: show on both') ?></i></p>
<?php
    }
} // class AuthorLatestTweetsWidget

function latest_tweets($instance) {
    /* Render latest tweets in author or single page only */
    if ( is_author() || is_single() ) {
        if ( ( $instance['show_only_on_author'] && is_author() ) || ( $instance['show_only_on_single'] && is_single() ) || ( !$instance['show_only_on_author'] && !$instance['show_only_on_single'] ) )
            $account = get_the_author_meta( "twitter" );
        else
            return '';
    }
    else {
        return '';
    }
    if (!$account) return '';

    $output = render_tweets($account, $instance['show'], $instance['updated']);

    return $output;
}

// query twitter api then render html output
// it's open for calling in other places outside this widget
function render_tweets($account, $show=3, $updated='') {
    // first look in cache
    $cache_key = 'latest-tweet-' . $account . '-' . $updated;
    $output = get_transient($cache_key);
    $expire = 60*60*2; // 2 hours

    if ($output === false) {
        // Not present in cache so load it
        $output = '';

        $params = array(
            // see https://dev.twitter.com/docs/api/1/get/statuses/user_timeline
            'screen_name'=>$account, // Twitter account name
            'include_entities'=>false,
            'exclude_replies'=>true,
        );
        $twitter_json_url = esc_url_raw( 'http://api.twitter.com/1/statuses/user_timeline.json?' . http_build_query($params), array('http', 'https') );
        unset($params);
        $response = wp_remote_get( $twitter_json_url, array( 'User-Agent' => 'AEIdeas Twitter Widget' ) );
        $response_code = wp_remote_retrieve_response_code( $response );

        if ( 200 == $response_code ) {
            $tweets = wp_remote_retrieve_body( $response );
            $tweets = json_decode( $tweets, true );
            if ( !is_array( $tweets ) || isset( $tweets['error'] ) ) {
                $tweets = 'error';
            }
        } else {
            $tweets = 'error';
        }

        if ( 'error' != $tweets ) {
            $output = '<ul>';

            $tweets_out = 0;
            foreach ((array) $tweets as $tweet) {
                if ( $tweets_out >= $show )
                    break;

                if ( empty( $tweet['text'] ) )
                continue;

                $text = make_clickable( esc_html( $tweet['text'] ) );

                /*
                 * Create links from plain text based on Twitter patterns
                 * @link http://github.com/mzsanford/twitter-text-rb/blob/master/lib/regex.rb Official Twitter regex
                 */
                $text = preg_replace_callback('/(^|[^0-9A-Z&\/]+)(#|\xef\xbc\x83)([0-9A-Z_]*[A-Z_]+[a-z0-9_\xc0-\xd6\xd8-\xf6\xf8\xff]*)/iu',  '_wpcom_widget_twitter_hashtag', $text);
                $text = preg_replace_callback('/([^a-zA-Z0-9_]|^)([@\xef\xbc\xa0]+)([a-zA-Z0-9_]{1,20})(\/[a-zA-Z][a-zA-Z0-9\x80-\xff-]{0,79})?/u', '_wpcom_widget_twitter_username', $text);
                if ( isset($tweet['id_str']) )
                    $tweet_id = urlencode($tweet['id_str']);
                else
                    $tweet_id = urlencode($tweet['id']);

                $output .= "<li>".$tweet['user']['name'];
                $output .= " <a href='".esc_url( "http://twitter.com/{$account}/statuses/{$tweet_id}" )."'>";
                $output .= "@{$account}</a>: {$text} "; 
                $output .= "<a href='".esc_url( "http://twitter.com/{$account}/statuses/{$tweet_id}" )."'>";
                $output .= "&gt;&gt;</a> ".date("Y/m/d", strtotime($tweet['created_at']));
                $output .= "</li>";
                unset($tweet_id);
                $tweets_out++;
            }

            $output .= '</ul>';
            $output .= '<p><a href="'.esc_url( "http://twitter.com/{$account}" ).'">more &gt;</a></p>';
        }

        // save the output to cache
        if ($output)
            set_transient($cache_key, $output, $expire);
    }

    return $output;
}

/**
 * Link a Twitter user mentioned in the tweet text to the user's page on Twitter.
 *
 * @param array $matches regex match
 * @return string Tweet text with inserted @user link
 */
function _wpcom_widget_twitter_username( $matches ) { // $matches has already been through wp_specialchars
    return "$matches[1]@<a href='" . esc_url( 'http://twitter.com/' . urlencode( $matches[3] ) ) . "'>$matches[3]</a>";
}

/**
 * Link a Twitter hashtag with a search results page on Twitter.com
 *
 * @param array $matches regex match
 * @return string Tweet text with inserted #hashtag link
 */
function _wpcom_widget_twitter_hashtag( $matches ) { // $matches has already been through wp_specialchars
    return "$matches[1]<a href='" . esc_url( 'http://twitter.com/search?q=%23' . urlencode( $matches[3] ) ) . "'>#$matches[3]</a>";
}

if ( ! function_exists( 'add_twitter_contactmethod' ) ):
// Update the profile form page to add new twitter contact info
function add_twitter_contactmethod( $contactmethods ) {
    $contactmethods['twitter'] = __('Twitter Username');
    return $contactmethods;
}
endif;
add_filter('user_contactmethods','add_twitter_contactmethod',10,1);

?>
