<?php
/**
 * Le lien entre les deux catalogues, gardé des deux côtés.
 *
 * Felar retient l'identifiant WooCommerce du produit ; WordPress retient en
 * retour l'identifiant Felar. Chacun peut ainsi reconstruire le lien si l'autre
 * se perd — une réinstallation, une restauration de sauvegarde, un import rejoué.
 *
 * <h2>Le lien n'est pas la référence</h2>
 * L'UGS est modifiable par le marchand. Le jour où il corrige une coquille, un
 * rapprochement par référence ne renomme pas un produit : il en <b>crée un
 * second</b>, avec son stock, ses ventes et sa fiche dédoublés. L'identifiant
 * WooCommerce, lui, ne change jamais — c'est pour cela qu'il porte le lien.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Liens {

	/** L'identifiant Felar de la fiche produit. */
	const META_ID = '_felar_id';

	/** La référence que Felar porte, fabriquée par lui ou reprise de l'UGS. */
	const META_REFERENCE = '_felar_reference';

	/** Le moment du dernier envoi réussi de cette ligne. */
	const META_ENVOI = '_felar_envoi';

	/**
	 * Retient ce que Felar a répondu pour une ligne.
	 *
	 * @param int    $id        L'identifiant WooCommerce (produit ou variation).
	 * @param string $felar_id  L'identifiant Felar.
	 * @param string $reference La référence retenue par Felar.
	 */
	public static function retenir( $id, $felar_id, $reference ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return;
		}
		if ( '' !== (string) $felar_id ) {
			update_post_meta( $id, self::META_ID, sanitize_text_field( $felar_id ) );
		}
		if ( '' !== (string) $reference ) {
			update_post_meta( $id, self::META_REFERENCE, sanitize_text_field( $reference ) );
		}
		update_post_meta( $id, self::META_ENVOI, time() );
	}

	/** L'identifiant Felar d'un produit, ou la chaîne vide. */
	public static function felar_id( $id ) {
		return (string) get_post_meta( (int) $id, self::META_ID, true );
	}

	/** La référence que Felar porte, ou la chaîne vide. */
	public static function reference( $id ) {
		return (string) get_post_meta( (int) $id, self::META_REFERENCE, true );
	}

	/**
	 * Écrit dans WooCommerce une référence fabriquée par Felar.
	 *
	 * <h3>Pourquoi le proposer</h3>
	 * Une référence qui n'existe que dans Felar est introuvable depuis le site, et
	 * le marchand se retrouve avec deux vocabulaires pour le même article — l'un
	 * au comptoir, l'autre à l'écran.
	 *
	 * <h3>Pourquoi ne jamais le faire en silence</h3>
	 * C'est une écriture dans son catalogue. Elle est donc déclenchée par un
	 * bouton, après qu'il a vu la liste.
	 *
	 * @param int    $id        Produit ou variation.
	 * @param string $reference La référence rendue par Felar.
	 * @return array{ecrit: bool, motif: string}
	 */
	public static function ecrire_reference( $id, $reference ) {
		$reference = trim( (string) $reference );
		if ( '' === $reference ) {
			return array(
				'ecrit' => false,
				'motif' => 'Aucune référence à écrire.',
			);
		}

		$produit = wc_get_product( (int) $id );
		if ( ! $produit ) {
			return array(
				'ecrit' => false,
				'motif' => 'Ce produit n\'existe plus dans WooCommerce.',
			);
		}

		$actuelle = (string) $produit->get_sku();
		if ( '' !== $actuelle ) {
			// Le marchand a saisi une UGS depuis l'import : la sienne gagne, c'est
			// lui qui possède ce champ.
			return array(
				'ecrit' => false,
				'motif' => 'Ce produit porte déjà l\'UGS « ' . $actuelle . ' ».',
			);
		}

		if ( function_exists( 'wc_product_has_unique_sku' ) && ! wc_product_has_unique_sku( (int) $id, $reference ) ) {
			return array(
				'ecrit' => false,
				'motif' => 'L\'UGS « ' . $reference . ' » est déjà portée par un autre produit de votre boutique.',
			);
		}

		try {
			$produit->set_sku( $reference );
			$produit->save();
		} catch ( Exception $echec ) {
			// WooCommerce refuse une UGS en doublon par une exception : la laisser
			// remonter arrêterait l'écriture des suivantes.
			return array(
				'ecrit' => false,
				'motif' => 'WooCommerce a refusé cette UGS : ' . $echec->getMessage(),
			);
		}

		return array(
			'ecrit' => true,
			'motif' => '',
		);
	}

	/**
	 * Efface les liens, à la désinstallation.
	 *
	 * Volontairement fait en SQL : parcourir quatre mille produits par l'API objet
	 * pour supprimer trois métadonnées ferait expirer la désinstallation. Ce sont
	 * des métadonnées de produit, pas des commandes — HPOS n'entre pas en jeu ici.
	 */
	public static function oublier() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)",
				self::META_ID,
				self::META_REFERENCE,
				self::META_ENVOI
			)
		);
	}

	private function __construct() {
	}
}
