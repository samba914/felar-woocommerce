<?php
/**
 * Ce que le rapport dit au marchand, en une phrase par cas.
 *
 * Les codes vivent dans le convertisseur, les phrases ici : les tests vérifient
 * des codes — stables — et l'écran affiche des phrases — réécrivables. Mélanger
 * les deux oblige à retoucher des tests chaque fois qu'on améliore une tournure.
 *
 * Chaque phrase dit **ce qui a été fait** et, quand il y a quelque chose à faire,
 * **quoi changer dans WooCommerce**. Une note qui se contente de constater laisse
 * le marchand devant un écran qu'il ne peut pas corriger.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Notes {

	/**
	 * Les décisions prises sur un produit accepté.
	 *
	 * @return array
	 */
	public static function decisions() {
		return array(
			'STOCK_PARENT'       => 'Le stock est suivi sur le produit et non sur ses variations : importé comme produit simple. Pour obtenir des déclinaisons dans Felar, cochez « Gérer le stock » sur chaque variation dans WooCommerce, puis relancez l\'import.',
			'STOCK_MIXTE'        => 'Une partie seulement des variations suit son stock : importé comme produit simple. Autrement, les autres variations réclameraient chacune le stock du produit parent, et vous vendriez plusieurs fois le même article.',
			'STOCK_CUMULE'       => 'Le stock retenu est la somme de celui des variations.',
			'AXE_UNIQUE'         => 'Un seul choix possible : importé comme produit simple, et l\'attribut devient un champ personnalisé. Une fiche à une seule déclinaison serait un niveau de navigation pour rien.',
			'AXE_INDEFINI'       => 'Une variation laisse un axe « au choix du client » : importé comme produit simple, faute de savoir quelle valeur lui attribuer.',
			'TROP_AXES'          => 'Plus de trois axes de déclinaison : importé comme produit simple. Felar en accepte trois au plus, au-delà la grille des déclinaisons n\'est plus lisible.',
			'TROP_VALEURS'       => 'Plus de cinquante valeurs sur un même axe : importé comme produit simple.',
			'TROP_DECLINAISONS'  => 'Plus de deux cents variations : importé comme produit simple. Au-delà, il ne s\'agit plus d\'une fiche mais de plusieurs.',
			'VALEUR_TROP_LONGUE' => 'Une valeur d\'attribut dépasse soixante-quatre caractères : importé comme produit simple. La raccourcir serait pire, car deux valeurs coupées au même endroit se confondraient en une seule déclinaison.',
			'DECLINAISON_DOUBLON' => 'Deux variations portent exactement la même combinaison d\'attributs : la seconde a été laissée de côté.',
			'PRIX_LE_PLUS_BAS'   => 'Produit variable importé comme produit simple : le prix retenu est le plus bas de ses variations. Vérifiez-le dans Felar.',
			'CATEGORIE_ABSENTE'  => 'Aucune catégorie dans WooCommerce : rangé dans « Non classé » chez Felar, où une fiche est toujours rangée quelque part. Classez-le dans WooCommerce et relancez pour le ranger ailleurs.',
			'CATEGORIE_MULTIPLE' => 'Plusieurs catégories : la plus profonde a été retenue, parce qu\'elle décrit l\'article là où une catégorie transversale ne décrit qu\'une mise en avant.',
			'CATEGORIE_TRONQUEE' => 'Arborescence de plus de quatre niveaux : les niveaux les plus généraux ont été retirés, le plus précis est conservé.',
			'IMAGE_IGNOREE'      => 'L\'image principale n\'est pas en HTTPS, ou son adresse est trop longue : la fiche part sans visuel. Vous pourrez en ajouter un dans Felar.',
			'SANS_REFERENCE'     => 'Pas d\'UGS : Felar fabrique une référence et vous la rend. Écrivez-la ensuite dans WooCommerce, sans quoi vous aurez deux vocabulaires pour le même article — l\'un au comptoir, l\'autre à l\'écran.',
			'INTITULE_TRONQUE'   => 'Intitulé plus long que deux cent cinquante-cinq caractères : il a été coupé.',
			'CHAMPS_TRONQUES'    => 'Plus de trente champs personnalisés : les suivants ont été laissés de côté.',
			'TAUX_INTROUVABLE'   => 'Aucun taux de taxe n\'est défini pour cette classe dans votre pays : le taux déclaré dans les réglages a été employé.',
		);
	}

	/**
	 * Les produits écartés, et pourquoi. Aucun n'est écarté en silence.
	 *
	 * @return array
	 */
	public static function exclusions() {
		return array(
			'TYPE_NON_GERE'      => 'Produit groupé ou externe : il n\'a ni prix ni stock propre, il n\'y a rien à importer.',
			'SANS_PRIX'          => 'Aucun prix : Felar exige un prix de vente sur chaque article vendable.',
			'SANS_INTITULE'      => 'Aucun intitulé.',
			'BROUILLON'          => 'Brouillon, article planifié ou en corbeille : laissé de côté. Cochez « importer aussi les brouillons » si vous les vendez au comptoir.',
			'SANS_VARIATION'     => 'Produit variable sans aucune variation exploitable.',
			'REFERENCE_DOUBLON'  => 'Cette UGS est portée par plusieurs articles de votre boutique. Les fusionner serait une décision de catalogue, pas une décision d\'import : corrigez-la dans WooCommerce, puis relancez.',
		);
	}

	/** La phrase d'un code, ou le code lui-même si on ne le connaît pas. */
	public static function phrase( $code ) {
		$toutes = array_merge( self::decisions(), self::exclusions() );
		return isset( $toutes[ $code ] ) ? $toutes[ $code ] : (string) $code;
	}

	private function __construct() {
	}
}
