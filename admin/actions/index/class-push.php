<?php

namespace Actions\Index;

require_once plugin_dir_path( __FILE__ ) . '../class-api-action.php';
require_once plugin_dir_path( __FILE__ ) . 'class-export.php';

use Actions\API_Action as API_Action;

class Push extends API_Action {

	/**
	 * Current content ID being exported.
	 *
	 * @var int
	 * @access private
	 */
	private $id;

	/**
	 * Current instance of the Exporter.
	 *
	 * @var Exporter
	 * @access private
	 */
	private $exporter;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings
	 * @param int $id
	 */
	function __construct( $settings, $id ) {
		parent::__construct( $settings );
		$this->id       = $id;
		$this->exporter = null;
	}

	/**
	 * Perform the push action.
	 *
	 * @access public
	 * @return boolean
	 */
	public function perform() {
		return $this->push();
	}

	/**
	 * Check if the post is in sync before updating in Apple News.
	 *
	 * @access private
	 * @return boolean
	 */
	private function is_post_in_sync() {
		$post = get_post( $this->id );

		if ( ! $post ) {
			throw new \Actions\Action_Exception( __( 'Could not find post with id ', 'apple-news' ) . $this->id );
		}

		$api_time   = get_post_meta( $this->id, 'apple_news_api_modified_at', true );
		$api_time   = strtotime( $api_time );
		$local_time = strtotime( $post->post_modified );

		$in_sync = $api_time >= $local_time;

		return apply_filters( 'apple_news_is_post_in_sync', $in_sync, $this->id, $api_time, $local_time );
	}

	/**
	 * Push the post using the API data.
	 *
	 * @access private
	 */
	private function push() {
		if ( ! $this->is_api_configuration_valid() ) {
			throw new \Actions\Action_Exception( __( 'Your API settings seem to be empty. Please fill in the API key, API secret and API channel fields in the plugin configuration page.', 'apple-news' ) );
		}

		// Ignore if the post is already in sync
		if ( $this->is_post_in_sync() ) {
			return;
		}

		// generate_article uses Exporter->generate, so we MUST clean the workspace
		// before and after its usage.
		$this->clean_workspace();
		list( $json, $bundles ) = $this->generate_article();

		try {
			// If there's an API ID, update, otherwise create.
			$remote_id = get_post_meta( $this->id, 'apple_news_api_id', true );
			$result    = null;

			do_action( 'apple_news_before_push', $this->id );

			if ( $remote_id ) {
				$revision = get_post_meta( $this->id, 'apple_news_api_revision', true );
				$result   = $this->get_api()->update_article( $remote_id, $revision, $json, $bundles );
			} else {
				$result = $this->get_api()->post_article_to_channel( $json, $this->get_setting( 'api_channel' ), $bundles );
			}

			// Save the ID that was assigned to this post in by the API
			update_post_meta( $this->id, 'apple_news_api_id', $result->data->id );
			update_post_meta( $this->id, 'apple_news_api_created_at', $result->data->createdAt );
			update_post_meta( $this->id, 'apple_news_api_modified_at', $result->data->modifiedAt );
			update_post_meta( $this->id, 'apple_news_api_share_url', $result->data->shareUrl );
			update_post_meta( $this->id, 'apple_news_api_revision', $result->data->revision );

			// If it's marked as deleted, remove the mark. Ignore otherwise.
			delete_post_meta( $this->id, 'apple_news_api_deleted' );

			do_action( 'apple_news_after_push', $this->id, $result );
		} catch ( \Push_API\Request\Request_Exception $e ) {
			if ( preg_match( '#WRONG_REVISION#', $e->getMessage() ) ) {
				throw new \Actions\Action_Exception( __( 'It seems like the article was updated by another call. If the problem persist, try removing and pushing again.', 'apple-news' ) );
			}

			throw new \Actions\Action_Exception( __( 'There has been an error with the API. Please make sure your API settings are correct and try again.', 'apple-news' ) );
		}

		// Finally, clean workspace
		$this->clean_workspace();
	}

	/**
	 * Clean up the workspace.
	 *
	 * @access private
	 */
	private function clean_workspace() {
		if ( is_null( $this->exporter ) ) {
			return;
		}

		$this->exporter->workspace()->clean_up();
	}

	/**
	 * Use the export action to get an instance of the Exporter. Use that to
	 * manually generate the workspace for upload, then clean it up.
	 *
	 * @access private
	 * @since 0.6.0
	 */
	private function generate_article() {
		$export_action = new Export( $this->settings, $this->id );
		$this->exporter = $export_action->fetch_exporter();
		$this->exporter->generate();

		return apply_filters( 'apple_news_generate_article', array( $this->exporter->get_json(), $this->exporter->get_bundles() ), $this->id );
	}

}
