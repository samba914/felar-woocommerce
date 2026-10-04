<?php
/**
 * Ce qu'on fait d'une ligne du flux de stock — et surtout ce qu'on ne fait pas.
 *
 * <h2>La règle dont tout dépend : on ÉCRIT, on ne décrémente jamais</h2>
 * C'est le piège le plus coûteux du contrat, et il ne se voit pas tout de suite.
 * WooCommerce retire déjà la quantité de son propre stock quand une commande est
 * passée ; Felar, de son côté, la réserve et la retire donc d'`available`.
 * Soustraire la valeur reçue au lieu de la <b>remplacer</b> compte chaque vente
 * deux fois, et le stock s'effondre en quelques jours — sans qu'aucune ligne du
 * journal ne désigne la cause.
 *
 * Écrire a un second mérite : la synchronisation devient <b>idempotente</b>. La
 * rejouer deux fois ne change rien, ce qui permet de la relancer après une
 * coupure sans se demander où elle s'était arrêtée.
 *
 * <h2>`available` n'est pas le stock physique</h2>
 * C'est ce qui reste vendable : le stock moins ce que des commandes en cours ont
 * déjà réservé. Le marchand verra donc parfois 10 dans Felar et 8 sur son site, et
 * ce n'est pas un défaut — les deux unités manquantes sont promises à quelqu'un.
 *
 * <h2>Rien n'est dépublié chez le marchand</h2>
 * Un article que Felar ne vend plus arrive avec `active: false`. On n'y touche
 * pas : dépublier une page que Google indexe est une décision de commerce, pas une
 * décision de synchronisation. Il est <b>nommé</b>, et le marchand tranche.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Stock_Regles {

	/**
	 * Décide du sort d'une ligne du flux.
	 *
	 * @param array $ligne Une entrée de `products` : `externalId`, `felarId`,
	 *                     `available`, `stockTracked`, `active`, `label`.
	 * @param array $faits Ce que WooCommerce dit de l'article visé :
	 *                     `existe`, `felar_id`, `suit_le_stock`, `stock`, `nom`.
	 * @return array{action: string, quantite: ?int, note: ?string}
	 */
	public static function decider( array $ligne, array $faits ) {
		if ( empty( $faits['existe'] ) ) {
			// Le marchand a supprimé l'article de sa boutique depuis l'import. Ce
			// n'est pas une anomalie, et ce n'est pas à nous de le recréer.
			return self::verdict( 'absent', null, 'PRODUIT_ABSENT' );
		}

		$felar_id = trim( (string) ( isset( $faits['felar_id'] ) ? $faits['felar_id'] : '' ) );
		$attendu  = trim( (string) ( isset( $ligne['felarId'] ) ? $ligne['felarId'] : '' ) );
		if ( '' !== $felar_id && '' !== $attendu && $felar_id !== $attendu ) {
			// L'article porte le lien d'une AUTRE fiche Felar. Cela arrive quand une
			// boutique a été branchée successivement sur deux comptes. Écrire ici
			// poserait le stock d'un compte sur le catalogue d'un autre.
			return self::verdict( 'lien_etranger', null, 'LIEN_ETRANGER' );
		}

		if ( isset( $ligne['active'] ) && false === (bool) $ligne['active'] ) {
			return self::verdict( 'retire', null, 'RETIRE_DE_FELAR' );
		}

		if ( isset( $ligne['stockTracked'] ) && false === (bool) $ligne['stockTracked'] ) {
			// Felar ne suit pas le stock de cet article — un service, un produit à la
			// commande. Il n'y a aucune quantité à publier.
			return self::verdict( 'non_suivi_felar', null, 'STOCK_NON_SUIVI_FELAR' );
		}

		if ( empty( $faits['suit_le_stock'] ) ) {
			// WooCommerce ne suit pas le stock de cet article : écrire une quantité
			// n'y changerait rien d'affiché. On pourrait cocher la case à sa place —
			// et faire passer d'un coup des articles en « rupture », donc invendables
			// sur son site. On le lui dit, il décide.
			return self::verdict( 'non_suivi_woo', null, 'STOCK_NON_SUIVI_WOO' );
		}

		$disponible = self::quantite( isset( $ligne['available'] ) ? $ligne['available'] : null );
		if ( null === $disponible ) {
			return self::verdict( 'sans_quantite', null, 'SANS_QUANTITE' );
		}

		$actuel = isset( $faits['stock'] ) && null !== $faits['stock'] ? (int) $faits['stock'] : null;
		if ( null !== $actuel && $actuel === $disponible ) {
			// Rien à écrire : la quantité est déjà la bonne. Sauter l'écriture évite
			// de réveiller les crochets de WooCommerce — et les extensions qui y sont
			// branchées — plusieurs fois par heure pour rien.
			return self::verdict( 'inchange', $disponible, null );
		}

		return self::verdict( 'ecrire', $disponible, null );
	}

	/**
	 * La quantité reçue, ramenée à un entier positif.
	 *
	 * Un disponible négatif existe côté Felar — une vente enregistrée malgré une
	 * rupture — mais il n'a pas de sens sur une vitrine : WooCommerce l'afficherait
	 * comme une quantité en réserve. Zéro dit la même chose, correctement.
	 */
	private static function quantite( $valeur ) {
		if ( null === $valeur || ! is_numeric( $valeur ) ) {
			return null;
		}
		return max( 0, (int) $valeur );
	}

	private static function verdict( $action, $quantite, $note ) {
		return array(
			'action'   => $action,
			'quantite' => $quantite,
			'note'     => $note,
		);
	}

	/** Les phrases du rapport de synchronisation, une par cas. */
	public static function phrases() {
		return array(
			'PRODUIT_ABSENT'        => 'Cet article n\'existe plus dans WooCommerce. Felar le garde ; votre site ne l\'a plus.',
			'LIEN_ETRANGER'         => 'Cet article est lié à une autre fiche Felar que celle annoncée. Rien n\'a été écrit : cela arrive quand une boutique a été branchée successivement sur deux comptes.',
			'RETIRE_DE_FELAR'       => 'Felar ne vend plus cet article. Son stock n\'est plus publié, et sa page reste en ligne : dépublier une page que Google indexe est votre décision, pas la nôtre.',
			'STOCK_NON_SUIVI_FELAR' => 'Felar ne suit pas le stock de cet article — un service, ou un produit à la commande. Il n\'y a aucune quantité à publier.',
			'STOCK_NON_SUIVI_WOO'   => 'WooCommerce ne gère pas le stock de cet article : la quantité de Felar ne s\'y affichera pas. Cochez « Gérer le stock » sur la fiche pour en profiter.',
			'SANS_QUANTITE'         => 'Felar n\'a envoyé aucune quantité pour cet article.',
		);
	}

	/** La phrase d'un code, ou le code lui-même. */
	public static function phrase( $code ) {
		$phrases = self::phrases();
		return isset( $phrases[ $code ] ) ? $phrases[ $code ] : (string) $code;
	}

	private function __construct() {
	}
}
