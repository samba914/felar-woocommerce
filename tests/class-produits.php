<?php
/**
 * Des produits WooCommerce de laboratoire.
 *
 * Écrire la forme brute à la main dans chaque cas de test la rendrait illisible et,
 * pire, ferait passer un test le jour où la forme change. Deux fabriques et des
 * surcharges suffisent.
 *
 * @package Felar_Connect
 */

final class Produits {

	/** Un produit simple, complet. */
	public static function simple( array $surcharges = array() ) {
		return array_merge(
			array(
				'id'            => 1287,
				'type'          => 'simple',
				'statut'        => 'publish',
				'reference'     => 'BZ-SAC-01',
				'intitule'      => 'Sac bazin brodé',
				'description'   => 'Coton bazin, doublure satin, 32 × 24 cm.',
				'prix'          => '24500.00',
				'classe_taxe'   => '',
				'statut_taxe'   => 'taxable',
				'suit_le_stock' => true,
				'stock'         => 4,
				'image'         => 'https://chez-fatou.sn/sac-bazin.jpg',
				'categories'    => array( array( 'Maroquinerie', 'Sacs à main' ) ),
				'attributs'     => array(),
				'variations'    => array(),
			),
			$surcharges
		);
	}

	/**
	 * Un produit variable à deux tailles, chacune suivant son propre stock —
	 * c'est-à-dire le cas où les déclinaisons sont légitimes.
	 */
	public static function variable( array $surcharges = array() ) {
		return array_merge(
			array(
				'id'            => 1301,
				'type'          => 'variable',
				'statut'        => 'publish',
				'reference'     => '',
				'intitule'      => 'Robe écrue',
				'description'   => 'Coton tissé main.',
				'prix'          => '32000',
				'classe_taxe'   => '',
				'statut_taxe'   => 'taxable',
				'suit_le_stock' => false,
				'stock'         => null,
				'image'         => 'https://chez-fatou.sn/robe.jpg',
				'categories'    => array( array( 'Prêt-à-porter', 'Robes' ) ),
				'attributs'     => array(
					array(
						'nom'             => 'Taille',
						'pour_variations' => true,
						'valeurs'         => array( '38', '40' ),
						'position'        => 0,
					),
					array(
						'nom'             => 'Matière',
						'pour_variations' => false,
						'valeurs'         => array( 'Coton' ),
						'position'        => 1,
					),
				),
				'variations'    => array(
					self::variation( 130138, '38', array( 'reference' => 'ROB-38' ) ),
					self::variation( 130140, '40', array( 'reference' => 'ROB-40' ) ),
				),
			),
			$surcharges
		);
	}

	/** Une variation. */
	public static function variation( $id, $taille, array $surcharges = array() ) {
		return array_merge(
			array(
				'id'            => $id,
				'reference'     => '',
				'prix'          => '32000',
				'classe_taxe'   => '',
				'statut_taxe'   => 'taxable',
				'suit_le_stock' => true,
				'stock'         => 2,
				'image'         => '',
				'poids'         => '',
				'choix'         => array( 'Taille' => $taille ),
			),
			$surcharges
		);
	}
}
