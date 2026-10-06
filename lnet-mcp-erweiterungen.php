<?php
/**
 * Plugin Name:       L.net MCP-Erweiterungen
 * Description:       Ergänzt den WP MCP Connector um Werkzeuge für Beaver-Builder-Entwürfe und geplante Aktualisierungen veröffentlichter Inhalte.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            L.net Web Solutions
 * Author URI:        https://www.l-net.biz/
 * License:           GPL-2.0-or-later
 * Text Domain:       lnet-mcp
 *
 * @package LNET_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LNET_MCP_VERSION', '1.1.0' );
define( 'LNET_MCP_PENDING_META', '_lnet_pending_update' );
define( 'LNET_MCP_CRON_HOOK', 'lnet_mcp_apply_pending_update' );

/*
 * -------------------------------------------------------------------------
 * Gemeinsame Hilfsfunktionen
 * -------------------------------------------------------------------------
 */

/**
 * Prüft, ob ein Beitrag mit Beaver Builder gebaut ist.
 *
 * @param int $post_id Beitrags-ID.
 * @return bool
 */
function lnet_mcp_is_bb_post( $post_id ) {
	return (bool) get_post_meta( $post_id, '_fl_builder_enabled', true );
}

/**
 * Bereinigt HTML so, wie WordPress es für den aktuellen Benutzer tun würde.
 *
 * @param string $value Wert.
 * @return string
 */
function lnet_mcp_clean_html( $value ) {
	$value = (string) $value;
	return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
}

/**
 * Formatiert einen UTC-Zeitstempel in der Zeitzone der Website.
 *
 * @param int $timestamp Zeitstempel.
 * @return string
 */
function lnet_mcp_local_time( $timestamp ) {
	return wp_date( 'd.m.Y H:i', (int) $timestamp );
}

/*
 * -------------------------------------------------------------------------
 * Geplante Aktualisierungen: Speicherung, Ausführung, Verwerfen
 * -------------------------------------------------------------------------
 */

/**
 * Liefert die geplante Aktualisierung eines Beitrags.
 *
 * @param int $post_id Beitrags-ID.
 * @return array<string,mixed>|null
 */
function lnet_mcp_get_pending( $post_id ) {
	$pending = get_post_meta( $post_id, LNET_MCP_PENDING_META, true );
	return is_array( $pending ) && ! empty( $pending['fields'] ) ? $pending : null;
}

/**
 * Wendet eine geplante Aktualisierung an.
 *
 * @param int  $post_id Beitrags-ID.
 * @param bool $force   Auch anwenden, wenn der Beitrag seit der Planung geändert wurde.
 * @return true|WP_Error
 */
function lnet_mcp_apply_pending( $post_id, $force = false ) {
	$post_id = (int) $post_id;
	$pending = lnet_mcp_get_pending( $post_id );
	$post    = get_post( $post_id );

	if ( ! $pending || ! $post ) {
		return new WP_Error( 'lnet_no_pending', 'Für diesen Inhalt ist keine Aktualisierung geplant.' );
	}

	if ( ! $force && ! empty( $pending['base_modified'] ) && $post->post_modified_gmt !== $pending['base_modified'] ) {
		$pending['status'] = 'conflict';
		update_post_meta( $post_id, LNET_MCP_PENDING_META, $pending );
		return new WP_Error( 'lnet_conflict', 'Der Inhalt wurde seit der Planung manuell geändert. Die geplante Aktualisierung wurde nicht angewendet und wartet auf eine Entscheidung.' );
	}

	// Im Cron-Lauf gibt es keinen angemeldeten Benutzer. Damit HTML nicht von
	// kses beschnitten wird, wird als der Benutzer gearbeitet, der die Änderung
	// geplant hat, sofern das gerade nicht ohnehin ein Benutzer tut.
	$previous_user = get_current_user_id();
	if ( ! $previous_user && ! empty( $pending['user_id'] ) ) {
		wp_set_current_user( (int) $pending['user_id'] );
		kses_init();
	}

	$data = array( 'ID' => $post_id );
	foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
		if ( array_key_exists( $field, $pending['fields'] ) ) {
			$data[ $field ] = $pending['fields'][ $field ];
		}
	}

	$result = wp_update_post( wp_slash( $data ), true );

	if ( ! $previous_user && ! empty( $pending['user_id'] ) ) {
		wp_set_current_user( 0 );
		kses_init();
	}

	if ( is_wp_error( $result ) ) {
		$pending['status'] = 'error';
		$pending['error']  = $result->get_error_message();
		update_post_meta( $post_id, LNET_MCP_PENDING_META, $pending );
		return $result;
	}

	delete_post_meta( $post_id, LNET_MCP_PENDING_META );
	wp_clear_scheduled_hook( LNET_MCP_CRON_HOOK, array( $post_id ) );

	return true;
}

/**
 * Verwirft eine geplante Aktualisierung.
 *
 * @param int $post_id Beitrags-ID.
 * @return void
 */
function lnet_mcp_discard_pending( $post_id ) {
	delete_post_meta( (int) $post_id, LNET_MCP_PENDING_META );
	wp_clear_scheduled_hook( LNET_MCP_CRON_HOOK, array( (int) $post_id ) );
}

add_action(
	LNET_MCP_CRON_HOOK,
	function ( $post_id ) {
		lnet_mcp_apply_pending( $post_id );
	}
);

/*
 * -------------------------------------------------------------------------
 * MCP-Werkzeuge
 * -------------------------------------------------------------------------
 */

add_filter(
	'wpmcp_tool_providers',
	function ( $providers ) {
		if ( class_exists( 'WPMCP_Schema' ) ) {
			$providers[] = new LNET_MCP_Tools();
		}
		return $providers;
	}
);

/**
 * Zusätzliche Werkzeuge für den WP MCP Connector.
 */
class LNET_MCP_Tools {

	/**
	 * Einstellungsschlüssel, die nur Gestaltung betreffen und beim Auslesen
	 * ausgeblendet werden, damit die Liste lesbar bleibt.
	 *
	 * @var string
	 */
	const STYLE_KEY_PATTERN = '/(color|_unit|padding|margin|border|font|typography|_medium|_responsive|_large|animation|visibility|shadow|gradient|bg_|_bg|width|height|align|spacing|_size|^class$|^id$|^type$|responsive_|_style$|_opacity|_hover)/i';

	/**
	 * Registriert die Werkzeuge.
	 *
	 * @param WPMCP_Registry $registry Registry.
	 * @return void
	 */
	public function register( $registry ) {
		$profiles = array( 'author', 'editor', 'admin' );

		$registry->add(
			array(
				'name'         => 'lnet_bb_get_layout',
				'title'        => 'Beaver-Builder-Layout auslesen',
				'description'  => 'Liest die Module einer mit Beaver Builder gebauten Seite in Lesereihenfolge aus, mit Knoten-ID, Modultyp und den inhaltlichen Einstellungen (Texte, Überschriften, Links). Verwende dieses Werkzeug statt wp_get_post, wenn eine Seite mit Beaver Builder gebaut ist: Deren Inhalt steckt in den Layoutdaten, nicht im normalen Seiteninhalt. Standardmäßig wird der Beaver-Builder-Entwurf gelesen, falls einer existiert, sonst die veröffentlichte Fassung. Mit node_id werden alle Einstellungen eines einzelnen Moduls geliefert.',
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => $profiles,
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'bb_get_layout' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'post_id' => WPMCP_Schema::integer( 'ID der Seite oder des Beitrags.', 1 ),
						'source'  => WPMCP_Schema::string( 'Welche Fassung gelesen wird: "auto" (Entwurf, falls vorhanden, sonst veröffentlicht), "draft" oder "published". Standard: auto.', array( 'auto', 'draft', 'published' ) ),
						'node_id' => WPMCP_Schema::string( 'Optional: Knoten-ID eines Moduls, um alle seine Einstellungen ungefiltert zu erhalten.' ),
					),
					array( 'post_id' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'lnet_bb_update_module',
				'title'        => 'Beaver-Builder-Modul im Entwurf ändern',
				'description'  => 'Ändert Einstellungen eines Moduls im Beaver-Builder-ENTWURF einer Seite. Die veröffentlichte Seite bleibt unverändert, bis jemand im Beaver Builder auf „Veröffentlichen“ klickt; „Verwerfen“ macht die Änderung rückgängig. Existiert noch kein Entwurf, wird er aus der veröffentlichten Fassung erzeugt; weitere Aufrufe bauen auf dem bestehenden Entwurf auf. Rufe vorher lnet_bb_get_layout auf, um Knoten-ID und Einstellungsnamen zu ermitteln. Standardmäßig dürfen nur bereits vorhandene Einstellungen geändert werden. Beispiele: Rich-Text-Modul {"text": "<p>…</p>"}, Überschriften-Modul {"heading": "…", "tag": "h4"}.',
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => $profiles,
				'annotations'  => array(
					'readOnlyHint'   => false,
					'destructiveHint' => false,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'bb_update_module' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'post_id'        => WPMCP_Schema::integer( 'ID der Seite oder des Beitrags.', 1 ),
						'node_id'        => WPMCP_Schema::string( 'Knoten-ID des Moduls aus lnet_bb_get_layout.' ),
						'settings'       => array(
							'type'        => 'object',
							'description' => 'Zu ändernde Einstellungen als Objekt aus Name und neuem Wert (Text, Zahl oder true/false).',
						),
						'allow_new_keys' => WPMCP_Schema::boolean( 'Auch Einstellungen setzen, die im Modul noch nicht existieren. Standard: false.' ),
					),
					array( 'post_id', 'node_id', 'settings' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'lnet_schedule_update',
				'title'        => 'Aktualisierung eines veröffentlichten Inhalts planen',
				'description'  => 'Plant eine Änderung an einem bereits veröffentlichten Inhalt (Titel, Inhalt und/oder Auszug), ohne ihn sofort zu ändern und ohne ihn offline zu nehmen. Die Seite bleibt bis zum Stichtag unverändert online; zum Stichtag wird die Änderung automatisch übernommen (mit Revision). Bis dahin kann sie im Backend auf der Bearbeiten-Seite oder unter Werkzeuge → Geplante Änderungen geprüft, sofort übernommen oder verworfen werden. Übergib immer den VOLLSTÄNDIGEN neuen Inhalt, nicht nur den geänderten Ausschnitt, und erhalte Blockkommentare. Nicht für Beaver-Builder-Seiten (dafür lnet_bb_update_module) und nicht für Entwürfe (die direkt mit wp_update_post bearbeitet werden). Existiert bereits eine geplante Änderung, schlägt der Aufruf fehl; hole sie mit lnet_get_pending_update, baue darauf auf und rufe erneut mit replace=true auf.',
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => $profiles,
				'callback'     => array( $this, 'schedule_update' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'post_id' => WPMCP_Schema::integer( 'ID des veröffentlichten Inhalts.', 1 ),
						'title'   => WPMCP_Schema::string( 'Neuer Titel (optional).' ),
						'content' => WPMCP_Schema::string( 'Neuer vollständiger Inhalt (optional).' ),
						'excerpt' => WPMCP_Schema::string( 'Neuer Auszug (optional).' ),
						'days'    => WPMCP_Schema::integer( 'In wie vielen Tagen die Änderung übernommen wird. Standard: 7.', 1, 90 ),
						'replace' => WPMCP_Schema::boolean( 'Eine bereits geplante Änderung ersetzen. Standard: false.' ),
					),
					array( 'post_id' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'lnet_get_pending_update',
				'title'        => 'Geplante Aktualisierungen anzeigen',
				'description'  => 'Liefert die geplante Aktualisierung eines Inhalts mit den vollständigen neuen Werten und dem Stichtag. Ohne post_id wird eine Übersicht aller geplanten Aktualisierungen der Website geliefert. Rufe dies auf, bevor du eine weitere Änderung an einem Inhalt planst, damit bereits geplante Änderungen erhalten bleiben.',
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'get_pending_update' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'post_id' => WPMCP_Schema::integer( 'ID des Inhalts (optional).', 1 ),
					)
				),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Beaver Builder
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Prüft Voraussetzungen für Beaver-Builder-Werkzeuge.
	 *
	 * @param int $post_id Beitrags-ID.
	 * @return WP_Post|WP_Error
	 */
	private function bb_check( $post_id ) {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return new WP_Error( 'lnet_bb_missing', 'Beaver Builder ist auf dieser Website nicht aktiv.' );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'lnet_not_found', 'Kein Inhalt mit dieser ID gefunden.' );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'lnet_forbidden', 'Keine Berechtigung, diesen Inhalt zu bearbeiten.' );
		}

		if ( ! lnet_mcp_is_bb_post( $post->ID ) ) {
			return new WP_Error( 'lnet_not_bb', 'Dieser Inhalt ist nicht mit Beaver Builder gebaut. Verwende wp_get_post bzw. lnet_schedule_update.' );
		}

		return $post;
	}

	/**
	 * Liest Layoutdaten.
	 *
	 * @param int    $post_id Beitrags-ID.
	 * @param string $status  draft oder published.
	 * @return array<string,object>
	 */
	private function bb_read( $post_id, $status ) {
		$data = FLBuilderModel::get_layout_data( $status, $post_id );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Bringt Knoten in Lesereihenfolge (Tiefensuche nach Position).
	 *
	 * @param array<string,object> $data Layoutdaten.
	 * @return object[]
	 */
	private function bb_ordered( array $data ) {
		$children = array();

		foreach ( $data as $node ) {
			$parent                = isset( $node->parent ) && $node->parent ? $node->parent : '__root__';
			$children[ $parent ][] = $node;
		}

		foreach ( $children as &$list ) {
			usort(
				$list,
				function ( $a, $b ) {
					return (int) ( isset( $a->position ) ? $a->position : 0 ) <=> (int) ( isset( $b->position ) ? $b->position : 0 );
				}
			);
		}
		unset( $list );

		$ordered = array();
		$walk    = function ( $parent ) use ( &$walk, &$ordered, $children ) {
			if ( empty( $children[ $parent ] ) ) {
				return;
			}
			foreach ( $children[ $parent ] as $node ) {
				$ordered[] = $node;
				$walk( $node->node );
			}
		};
		$walk( '__root__' );

		return $ordered;
	}

	/**
	 * Werkzeug: Layout auslesen.
	 *
	 * @param array<string,mixed> $args Argumente.
	 * @return array<string,mixed>|WP_Error
	 */
	public function bb_get_layout( array $args ) {
		$post = $this->bb_check( (int) $args['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$draft     = $this->bb_read( $post->ID, 'draft' );
		$published = $this->bb_read( $post->ID, 'published' );
		$source    = isset( $args['source'] ) ? $args['source'] : 'auto';

		if ( 'auto' === $source ) {
			$source = ! empty( $draft ) ? 'draft' : 'published';
		}

		$data = 'draft' === $source ? $draft : $published;

		if ( ! empty( $args['node_id'] ) ) {
			$id = (string) $args['node_id'];
			if ( ! isset( $data[ $id ] ) ) {
				return new WP_Error( 'lnet_node_missing', sprintf( 'Knoten "%s" existiert in der Fassung "%s" nicht.', $id, $source ) );
			}
			return array(
				'post_id'  => $post->ID,
				'source'   => $source,
				'node'     => $data[ $id ],
			);
		}

		$modules = array();
		$depth   = array();

		foreach ( $this->bb_ordered( $data ) as $node ) {
			$depth[ $node->node ] = isset( $node->parent, $depth[ $node->parent ] ) ? $depth[ $node->parent ] + 1 : 0;

			if ( 'module' !== $node->type ) {
				continue;
			}

			$settings = isset( $node->settings ) ? (array) $node->settings : array();
			$content  = array();

			foreach ( $settings as $key => $value ) {
				if ( ! is_scalar( $value ) || '' === (string) $value || preg_match( self::STYLE_KEY_PATTERN, (string) $key ) ) {
					continue;
				}
				$content[ $key ] = $value;
			}

			$modules[] = array(
				'node_id'     => $node->node,
				'module_type' => isset( $settings['type'] ) ? $settings['type'] : '',
				'parent'      => $node->parent,
				'depth'       => $depth[ $node->node ],
				'settings'    => $content,
			);
		}

		return array(
			'post_id'           => $post->ID,
			'title'             => get_the_title( $post ),
			'source'            => $source,
			'has_unpublished_draft' => ! empty( $draft ) && maybe_serialize( $draft ) !== maybe_serialize( $published ),
			'builder_url'       => add_query_arg( 'fl_builder', '', get_permalink( $post ) ),
			'modules'           => $modules,
			'note'              => 'Gestaltungseinstellungen (Farben, Abstände, Schriften usw.) sind ausgeblendet. Mit node_id erhältst du alle Einstellungen eines Moduls.',
		);
	}

	/**
	 * Werkzeug: Modul im Entwurf ändern.
	 *
	 * @param array<string,mixed> $args Argumente.
	 * @return array<string,mixed>|WP_Error
	 */
	public function bb_update_module( array $args ) {
		$post = $this->bb_check( (int) $args['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$changes = (array) $args['settings'];
		if ( empty( $changes ) ) {
			return new WP_Error( 'lnet_no_changes', 'settings ist leer. Übergib mindestens eine Einstellung.' );
		}

		$draft = $this->bb_read( $post->ID, 'draft' );
		if ( empty( $draft ) ) {
			// Noch kein Entwurf: aus der veröffentlichten Fassung erzeugen.
			$draft = $this->bb_read( $post->ID, 'published' );
		}

		$id = (string) $args['node_id'];
		if ( ! isset( $draft[ $id ] ) || 'module' !== $draft[ $id ]->type ) {
			return new WP_Error( 'lnet_node_missing', sprintf( 'Im Entwurf gibt es kein Modul mit der Knoten-ID "%s". Rufe lnet_bb_get_layout auf.', $id ) );
		}

		// Tiefe Kopie, damit die veröffentlichten Daten im Objekt-Cache nicht mitverändert werden.
		$draft    = unserialize( serialize( $draft ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$node     = $draft[ $id ];
		$settings = is_object( $node->settings ) ? $node->settings : (object) $node->settings;
		$allow    = ! empty( $args['allow_new_keys'] );
		$before   = array();
		$after    = array();

		foreach ( $changes as $key => $value ) {
			$key = (string) $key;

			if ( 'type' === $key ) {
				return new WP_Error( 'lnet_forbidden_key', 'Der Modultyp kann nicht geändert werden.' );
			}

			if ( ! $allow && ! property_exists( $settings, $key ) ) {
				return new WP_Error( 'lnet_unknown_key', sprintf( 'Das Modul hat keine Einstellung "%s". Vorhandene inhaltliche Einstellungen zeigt lnet_bb_get_layout mit node_id; für neue Einstellungen allow_new_keys=true setzen.', $key ) );
			}

			if ( ! is_scalar( $value ) && null !== $value ) {
				return new WP_Error( 'lnet_invalid_value', sprintf( 'Der Wert für "%s" muss Text, Zahl oder true/false sein.', $key ) );
			}

			$before[ $key ]   = property_exists( $settings, $key ) ? $settings->$key : null;
			$settings->$key   = is_string( $value ) ? lnet_mcp_clean_html( $value ) : $value;
			$after[ $key ]    = $settings->$key;
		}

		$node->settings = $settings;
		$draft[ $id ]   = $node;

		FLBuilderModel::update_layout_data( $draft, 'draft', $post->ID );

		// Entwurfs-CSS/JS neu erzeugen lassen (z. B. nach Änderung eines Überschriften-Tags).
		if ( method_exists( 'FLBuilderModel', 'delete_all_asset_cache' ) ) {
			FLBuilderModel::delete_all_asset_cache( $post->ID );
		}

		return array(
			'updated'     => true,
			'post_id'     => $post->ID,
			'node_id'     => $id,
			'before'      => $before,
			'after'       => $after,
			'builder_url' => add_query_arg( 'fl_builder', '', get_permalink( $post ) ),
			'note'        => 'Die Änderung liegt im Beaver-Builder-Entwurf. Die Live-Seite ist unverändert, bis im Beaver Builder „Veröffentlichen“ geklickt wird.',
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Geplante Aktualisierungen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Werkzeug: Aktualisierung planen.
	 *
	 * @param array<string,mixed> $args Argumente.
	 * @return array<string,mixed>|WP_Error
	 */
	public function schedule_update( array $args ) {
		$post = get_post( (int) $args['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'lnet_not_found', 'Kein Inhalt mit dieser ID gefunden.' );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'lnet_forbidden', 'Keine Berechtigung, diesen Inhalt zu bearbeiten.' );
		}

		if ( lnet_mcp_is_bb_post( $post->ID ) ) {
			return new WP_Error( 'lnet_is_bb', 'Dieser Inhalt ist mit Beaver Builder gebaut. Verwende lnet_bb_get_layout und lnet_bb_update_module.' );
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'lnet_not_published', sprintf( 'Der Inhalt hat den Status "%s" und ist nicht veröffentlicht. Nicht veröffentlichte Inhalte werden direkt mit wp_update_post bearbeitet.', $post->post_status ) );
		}

		$fields = array();
		if ( isset( $args['title'] ) ) {
			$fields['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$fields['post_content'] = lnet_mcp_clean_html( $args['content'] );
		}
		if ( isset( $args['excerpt'] ) ) {
			$fields['post_excerpt'] = sanitize_textarea_field( $args['excerpt'] );
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'lnet_no_changes', 'Übergib mindestens title, content oder excerpt.' );
		}

		$existing = lnet_mcp_get_pending( $post->ID );
		if ( $existing && empty( $args['replace'] ) ) {
			return new WP_Error( 'lnet_pending_exists', sprintf( 'Für diesen Inhalt ist bereits eine Änderung zum %s geplant. Hole sie mit lnet_get_pending_update, übernimm ihre Inhalte in deine Änderung und rufe dieses Werkzeug mit replace=true erneut auf.', lnet_mcp_local_time( $existing['apply_at'] ) ) );
		}

		$days     = isset( $args['days'] ) ? (int) $args['days'] : 7;
		$apply_at = time() + $days * DAY_IN_SECONDS;

		update_post_meta(
			$post->ID,
			LNET_MCP_PENDING_META,
			array(
				'fields'        => $fields,
				'apply_at'      => $apply_at,
				'created_at'    => time(),
				'user_id'       => get_current_user_id(),
				'base_modified' => $post->post_modified_gmt,
				'status'        => 'scheduled',
			)
		);

		wp_clear_scheduled_hook( LNET_MCP_CRON_HOOK, array( $post->ID ) );
		wp_schedule_single_event( $apply_at, LNET_MCP_CRON_HOOK, array( $post->ID ) );

		return array(
			'scheduled'  => true,
			'post_id'    => $post->ID,
			'title'      => get_the_title( $post ),
			'fields'     => array_keys( $fields ),
			'apply_at'   => lnet_mcp_local_time( $apply_at ),
			'review_url' => get_edit_post_link( $post->ID, 'raw' ),
			'preview_url' => lnet_mcp_preview_url( $post->ID ),
			'overview'   => admin_url( 'tools.php?page=lnet-pending-updates' ),
			'note'       => 'Die Seite bleibt bis zum Stichtag unverändert online. Vorschau im Frontend unter preview_url (nur für angemeldete Bearbeiter sichtbar). Prüfen, sofort übernehmen oder verwerfen: auf der Bearbeiten-Seite im Kasten „Geplante Änderung“ oder unter Werkzeuge → Geplante Änderungen.',
		);
	}

	/**
	 * Werkzeug: Geplante Aktualisierungen anzeigen.
	 *
	 * @param array<string,mixed> $args Argumente.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_pending_update( array $args ) {
		if ( ! empty( $args['post_id'] ) ) {
			$post = get_post( (int) $args['post_id'] );
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'lnet_not_found', 'Inhalt nicht gefunden oder keine Berechtigung.' );
			}
			$pending = lnet_mcp_get_pending( $post->ID );
			if ( ! $pending ) {
				return array(
					'post_id' => $post->ID,
					'pending' => null,
				);
			}
			return array(
				'post_id'  => $post->ID,
				'title'    => get_the_title( $post ),
				'status'   => $pending['status'],
				'apply_at' => lnet_mcp_local_time( $pending['apply_at'] ),
				'preview_url' => lnet_mcp_preview_url( $post->ID ),
				'fields'   => $pending['fields'],
			);
		}

		$list = array();
		foreach ( lnet_mcp_all_pending_ids() as $id ) {
			$pending = lnet_mcp_get_pending( $id );
			if ( ! $pending || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$list[] = array(
				'post_id'  => $id,
				'title'    => get_the_title( $id ),
				'status'   => $pending['status'],
				'apply_at' => lnet_mcp_local_time( $pending['apply_at'] ),
				'fields'   => array_keys( $pending['fields'] ),
			);
		}

		return array( 'pending' => $list );
	}
}

/**
 * IDs aller Inhalte mit geplanter Aktualisierung.
 *
 * @return int[]
 */
function lnet_mcp_all_pending_ids() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", LNET_MCP_PENDING_META ) ) );
}

/*
 * -------------------------------------------------------------------------
 * Backend: Prüfen, übernehmen, verwerfen
 * -------------------------------------------------------------------------
 */

/**
 * Gibt die Unterschiede einer geplanten Aktualisierung als HTML aus.
 *
 * @param int $post_id Beitrags-ID.
 * @return void
 */
function lnet_mcp_render_pending( $post_id ) {
	$pending = lnet_mcp_get_pending( $post_id );
	$post    = get_post( $post_id );

	if ( ! $pending || ! $post ) {
		return;
	}

	$labels = array(
		'post_title'   => 'Titel',
		'post_content' => 'Inhalt',
		'post_excerpt' => 'Auszug',
	);

	if ( 'conflict' === $pending['status'] ) {
		echo '<p style="color:#b32d2e"><strong>Nicht übernommen:</strong> Der Inhalt wurde nach der Planung manuell geändert. Bitte prüfen und entscheiden.</p>';
	} elseif ( 'error' === $pending['status'] ) {
		echo '<p style="color:#b32d2e"><strong>Fehler beim Übernehmen:</strong> ' . esc_html( isset( $pending['error'] ) ? $pending['error'] : '' ) . '</p>';
	} else {
		echo '<p>Wird automatisch übernommen am <strong>' . esc_html( lnet_mcp_local_time( $pending['apply_at'] ) ) . ' Uhr</strong>. Bis dahin bleibt die Seite unverändert online.</p>';
	}

	foreach ( $pending['fields'] as $field => $new ) {
		$old  = isset( $post->$field ) ? $post->$field : '';
		$diff = wp_text_diff(
			$old,
			$new,
			array(
				'title_left'  => 'Aktuell',
				'title_right' => 'Geplant',
			)
		);
		echo '<h4>' . esc_html( isset( $labels[ $field ] ) ? $labels[ $field ] : $field ) . '</h4>';
		echo $diff ? $diff : '<p><em>Keine Unterschiede.</em></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	$actions = array(
		'apply'   => 'Jetzt übernehmen',
		'discard' => 'Verwerfen',
	);

	echo '<p>';
	echo '<a class="button" href="' . esc_url( lnet_mcp_preview_url( $post_id ) ) . '" target="_blank">Vorschau im Frontend</a> ';
	foreach ( $actions as $action => $label ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=lnet_pending&do=' . $action . '&post_id=' . (int) $post_id ),
			'lnet_pending_' . (int) $post_id
		);
		$class = 'apply' === $action ? 'button button-primary' : 'button';
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
	}
	echo '</p>';
}

add_action(
	'add_meta_boxes',
	function ( $post_type, $post ) {
		if ( $post instanceof WP_Post && lnet_mcp_get_pending( $post->ID ) ) {
			add_meta_box(
				'lnet-pending-update',
				'Geplante Änderung',
				function ( $post ) {
					lnet_mcp_render_pending( $post->ID );
				},
				null,
				'normal',
				'high'
			);
		}
	},
	10,
	2
);

add_action(
	'admin_post_lnet_pending',
	function () {
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$do      = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'lnet_pending_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Keine Berechtigung.' );
		}

		$message = '';
		if ( 'apply' === $do ) {
			$result  = lnet_mcp_apply_pending( $post_id, true );
			$message = is_wp_error( $result ) ? 'error' : 'applied';
		} elseif ( 'discard' === $do ) {
			lnet_mcp_discard_pending( $post_id );
			$message = 'discarded';
		}

		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'tools.php?page=lnet-pending-updates' );
		wp_safe_redirect( add_query_arg( 'lnet_pending', $message, $back ) );
		exit;
	}
);

add_action(
	'admin_notices',
	function () {
		if ( empty( $_GET['lnet_pending'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$messages = array(
			'applied'   => array( 'success', 'Die geplante Änderung wurde übernommen.' ),
			'discarded' => array( 'success', 'Die geplante Änderung wurde verworfen.' ),
			'error'     => array( 'error', 'Die geplante Änderung konnte nicht übernommen werden.' ),
		);
		$key = sanitize_key( $_GET['lnet_pending'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}
);

add_action(
	'admin_menu',
	function () {
		$count = 0;
		foreach ( lnet_mcp_all_pending_ids() as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				++$count;
			}
		}
		$bubble = $count ? ' <span class="awaiting-mod">' . (int) $count . '</span>' : '';

		add_management_page(
			'Geplante Änderungen',
			'Geplante Änderungen' . $bubble,
			'edit_posts',
			'lnet-pending-updates',
			function () {
				echo '<div class="wrap"><h1>Geplante Änderungen</h1>';
				$ids = array_filter(
					lnet_mcp_all_pending_ids(),
					function ( $id ) {
						return current_user_can( 'edit_post', $id );
					}
				);
				if ( ! $ids ) {
					echo '<p>Derzeit sind keine Änderungen geplant.</p></div>';
					return;
				}
				foreach ( $ids as $id ) {
					echo '<div class="card" style="max-width:none">';
					echo '<h2><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a> <small>(ID ' . (int) $id . ', <a href="' . esc_url( get_permalink( $id ) ) . '" target="_blank">live ansehen</a>)</small></h2>';
					lnet_mcp_render_pending( $id );
					echo '</div>';
				}
				echo '</div>';
			}
		);
	}
);

/*
 * -------------------------------------------------------------------------
 * Frontend-Vorschau geplanter Änderungen
 * -------------------------------------------------------------------------
 *
 * Mit ?lnet_preview=1 an der Seiten-URL sehen angemeldete Benutzer, die den
 * Inhalt bearbeiten dürfen, die Seite so, wie sie nach Übernahme der geplanten
 * Änderung aussieht. Alle anderen Besucher sehen unverändert die Live-Fassung.
 * Die Beitragsobjekte selbst werden nicht verändert, sondern nur die Ausgabe
 * (Titel, Inhalt, Auszug), damit nichts davon in einen Objekt-Cache gelangt.
 */

/**
 * URL der Frontend-Vorschau.
 *
 * @param int $post_id Beitrags-ID.
 * @return string
 */
function lnet_mcp_preview_url( $post_id ) {
	return add_query_arg( 'lnet_preview', '1', get_permalink( $post_id ) );
}

/**
 * Liefert die geplante Änderung, wenn für den Beitrag gerade eine Vorschau
 * angezeigt werden darf.
 *
 * @param int $post_id Beitrags-ID.
 * @return array<string,mixed>|null
 */
function lnet_mcp_preview_pending( $post_id ) {
	static $allowed = null;

	if ( null === $allowed ) {
		$allowed = ! is_admin() && ! empty( $_GET['lnet_preview'] ) && is_user_logged_in(); // phpcs:ignore WordPress.Security.NonceVerification
	}

	if ( ! $allowed || ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		return null;
	}

	return lnet_mcp_get_pending( $post_id );
}

add_action(
	'template_redirect',
	function () {
		if ( ! is_singular() || ! lnet_mcp_preview_pending( get_queried_object_id() ) ) {
			return;
		}

		// Vorschau niemals zwischenspeichern oder indexieren lassen.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		$fields = lnet_mcp_preview_pending( get_queried_object_id() )['fields'];

		if ( isset( $fields['post_content'] ) ) {
			add_filter(
				'the_content',
				function ( $content ) use ( $fields ) {
					return get_the_ID() === get_queried_object_id() ? $fields['post_content'] : $content;
				},
				0
			);
		}

		if ( isset( $fields['post_title'] ) ) {
			$title_filter = function ( $title, $id = 0 ) use ( $fields ) {
				$id = $id instanceof WP_Post ? $id->ID : (int) $id;
				return $id === get_queried_object_id() ? esc_html( $fields['post_title'] ) : $title;
			};
			add_filter( 'the_title', $title_filter, 0, 2 );
			add_filter( 'single_post_title', $title_filter, 0, 2 );
		}

		if ( isset( $fields['post_excerpt'] ) ) {
			add_filter(
				'get_the_excerpt',
				function ( $excerpt, $post = null ) use ( $fields ) {
					return $post && $post->ID === get_queried_object_id() ? $fields['post_excerpt'] : $excerpt;
				},
				0,
				2
			);
		}

		$banner = function () {
			static $done = false;
			if ( $done ) {
				return;
			}
			$done    = true;
			$id      = get_queried_object_id();
			$pending = lnet_mcp_get_pending( $id );
			$review  = admin_url( 'tools.php?page=lnet-pending-updates' );
			printf(
				'<div style="position:sticky;top:0;z-index:99999;background:#1d2327;color:#fff;font:14px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;padding:10px 16px;text-align:center">'
				. '<strong>Vorschau der geplanten Änderung</strong> &ndash; nur für dich sichtbar. Wird am %s Uhr übernommen. '
				. '<a href="%s" style="color:#72aee6">Prüfen, übernehmen oder verwerfen</a> &middot; <a href="%s" style="color:#72aee6">Aktuelle Fassung ansehen</a></div>',
				esc_html( lnet_mcp_local_time( $pending['apply_at'] ) ),
				esc_url( $review ),
				esc_url( get_permalink( $id ) )
			);
		};
		add_action( 'wp_body_open', $banner );
		add_action( 'wp_footer', $banner );
	}
);

// Beim Deaktivieren keine verwaisten Cron-Ereignisse hinterlassen. Geplante
// Änderungen bleiben als Metadaten erhalten und werden beim erneuten
// Aktivieren wieder eingeplant.
register_deactivation_hook(
	__FILE__,
	function () {
		foreach ( lnet_mcp_all_pending_ids() as $id ) {
			wp_clear_scheduled_hook( LNET_MCP_CRON_HOOK, array( $id ) );
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		foreach ( lnet_mcp_all_pending_ids() as $id ) {
			$pending = lnet_mcp_get_pending( $id );
			if ( $pending && 'scheduled' === $pending['status'] && ! wp_next_scheduled( LNET_MCP_CRON_HOOK, array( $id ) ) ) {
				wp_schedule_single_event( max( time() + 60, (int) $pending['apply_at'] ), LNET_MCP_CRON_HOOK, array( $id ) );
			}
		}
	}
);
