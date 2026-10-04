<?php
/**
 * Le journal, et la clé qui n'y entre jamais.
 *
 * <h2>Deux journaux, deux usages</h2>
 * Celui de WooCommerce (`WC_Logger`) reçoit le détail technique, pour la personne
 * qui dépanne. Une liste courte, gardée en option, reçoit les <b>fins de course</b>
 * — « 412 lignes envoyées, 3 refusées » — pour le marchand, qui n'ouvrira jamais
 * un fichier de journal.
 *
 * <h2>Le filtre n'est pas décoratif</h2>
 * Une clé recopiée dans un journal est une clé qu'il faut révoquer : les fichiers
 * de WooCommerce sont lisibles depuis l'administration, et un marchand qui envoie
 * son journal au support envoie alors son mot de passe avec. Tout ce qui
 * ressemble à une clé est donc masqué avant écriture, y compris dans un message
 * venu du serveur.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Journal {

	const OPTION = 'felar_connect_journal';

	/** Vingt entrées : de quoi voir une dérive, pas de quoi remplir la base. */
	const ENTREES_MAX = 20;

	/** Écrit dans le journal technique. */
	public static function noter( $message, $niveau = 'info' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log(
			$niveau,
			self::masquer_les_cles( $message ),
			array( 'source' => 'felar-connect' )
		);
	}

	/**
	 * Masque tout ce qui ressemble à une clé de connecteur.
	 *
	 * On garde le préfixe : il dit le mode, et c'est ce qu'on cherche à lire dans
	 * un journal. Le secret, lui, disparaît.
	 */
	public static function masquer_les_cles( $texte ) {
		return preg_replace(
			'/\b(ck_(?:live|test)_)[A-Za-z0-9_-]{4,}/',
			'$1…',
			(string) $texte
		);
	}

	/**
	 * Retient une fin de course, visible par le marchand.
	 *
	 * @param string $quoi    'import', 'appairage'…
	 * @param string $verdict 'termine', 'echec', 'arrete'.
	 * @param string $resume  Une phrase, déjà lisible.
	 * @param array  $chiffre Compteurs à afficher.
	 */
	public static function retenir( $quoi, $verdict, $resume, array $chiffre = array() ) {
		$entrees = get_option( self::OPTION, array() );
		if ( ! is_array( $entrees ) ) {
			$entrees = array();
		}

		array_unshift(
			$entrees,
			array(
				'quand'   => time(),
				'quoi'    => (string) $quoi,
				'verdict' => (string) $verdict,
				'resume'  => self::masquer_les_cles( $resume ),
				'chiffre' => $chiffre,
			)
		);

		update_option( self::OPTION, array_slice( $entrees, 0, self::ENTREES_MAX ), false );
	}

	/** Les entrées, la plus récente d'abord. */
	public static function entrees() {
		$entrees = get_option( self::OPTION, array() );
		return is_array( $entrees ) ? $entrees : array();
	}

	/** Oublie tout, à la désinstallation. */
	public static function oublier() {
		delete_option( self::OPTION );
	}

	private function __construct() {
	}
}
