<?php
/**
 * Les références fabriquées par Felar, réécrites dans WooCommerce.
 *
 * Felar exige une référence sur chaque produit : c'est ce que le caissier tape et
 * ce qui s'imprime sur l'étiquette. Quand la boutique n'en a pas, Felar en
 * fabrique une et la rend — et il faut la <b>ramener dans WooCommerce</b>, sans
 * quoi le marchand se retrouve avec deux vocabulaires pour le même article :
 * l'un au comptoir, l'autre à l'écran.
 *
 * <h2>Proposé, jamais silencieux</h2>
 * C'est une écriture dans le catalogue du marchand. Elle se déclenche par un
 * bouton, après qu'il a vu combien d'articles sont concernés. Un refus ne casse
 * rien : le lien entre les deux catalogues ne dépend pas de l'UGS, mais de
 * l'identifiant WooCommerce.
 *
 * <h2>Pourquoi on ne garde pas la liste dans une option</h2>
 * Un catalogue sans UGS peut en compter quatre mille. La question « quelles
 * références attendent d'être écrites ? » se répond en cherchant les articles qui
 * portent une référence Felar et pas d'UGS : c'est exact, borné, et cela reste
 * vrai après une coupure ou un second import.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_References {

	/** Cinquante écritures par appel : l'écran garde la main, et rien n'expire. */
	const PAR_APPEL = 50;

	/**
	 * Les identifiants des articles à rattraper.
	 *
	 * La condition est posée dans la requête, pas après : filtrer en PHP une page
	 * de cinquante résultats rendrait trois lignes utiles et ferait croire qu'il
	 * n'en reste plus que trois.
	 *
	 * @param int $combien -1 pour tous.
	 * @return int[]
	 */
	private function identifiants( $combien ) {
		return (array) get_posts(
			array(
				'post_type'        => array( 'product', 'product_variation' ),
				'post_status'      => array( 'publish', 'private', 'draft' ),
				'posts_per_page'   => (int) $combien,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'     => Felar_Liens::META_REFERENCE,
						'compare' => 'EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_sku',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_sku',
							'value'   => '',
							'compare' => '=',
						),
					),
				),
			)
		);
	}

	/**
	 * Les articles qui portent une référence Felar sans UGS dans WooCommerce.
	 *
	 * @param int $combien Le nombre à rendre.
	 * @return array Liste de `array( 'id', 'nom', 'reference' )`.
	 */
	public function en_attente( $combien = self::PAR_APPEL ) {
		$attente = array();

		foreach ( $this->identifiants( $combien ) as $id ) {
			$produit = wc_get_product( (int) $id );
			if ( ! $produit || '' !== (string) $produit->get_sku() ) {
				continue;
			}
			$reference = Felar_Liens::reference( (int) $id );
			if ( '' === $reference ) {
				continue;
			}
			$attente[] = array(
				'id'        => (int) $id,
				'nom'       => $produit->get_name(),
				'reference' => $reference,
			);
		}

		return $attente;
	}

	/** Combien attendent, en tout. */
	public function combien_en_attente() {
		return count( $this->identifiants( -1 ) );
	}

	/**
	 * Écrit une tranche de références.
	 *
	 * @return array{ecrites: int, refusees: array, restantes: int}
	 */
	public function ecrire_une_tranche() {
		$ecrites  = 0;
		$refusees = array();

		foreach ( $this->en_attente() as $article ) {
			$verdict = Felar_Liens::ecrire_reference( $article['id'], $article['reference'] );
			if ( $verdict['ecrit'] ) {
				$ecrites++;
				continue;
			}
			$refusees[] = array(
				'nom'   => $article['nom'],
				'motif' => $verdict['motif'],
			);
		}

		if ( $ecrites > 0 ) {
			Felar_Journal::noter( $ecrites . ' UGS écrites dans WooCommerce depuis Felar.' );
		}

		return array(
			'ecrites'   => $ecrites,
			'refusees'  => $refusees,
			'restantes' => $this->combien_en_attente(),
		);
	}
}
