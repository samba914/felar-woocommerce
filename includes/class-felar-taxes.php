<?php
/**
 * La TVA, résolue ici et pas chez Felar.
 *
 * WooCommerce ne porte pas un taux sur le produit : il porte une **classe** de
 * taxe (standard, réduite, zéro) et garde les taux dans une table séparée, par
 * pays. Felar attend un taux, en pourcentage, et refuse d'en deviner un — un
 * taux supposé fausse un catalogue entier sans que rien ne le signale.
 *
 * Deux réglages de boutique commandent tout, et ils se lisent, ils ne se
 * déduisent pas du premier article :
 *
 * - **la taxe peut être désactivée** : beaucoup de boutiques la coupent. Il n'y a
 *   alors aucun taux à lire, et c'est le marchand qui déclare le sien une fois ;
 * - **« prix saisis taxe comprise »** est global à la boutique. Se tromper de sens
 *   inverse la TVA sur tout le catalogue.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Taxes {

	/**
	 * Le taux d'un produit, et la note à porter au rapport s'il y en a une.
	 *
	 * @param bool   $taxe_active     `wc_tax_enabled()`.
	 * @param string $statut_taxe     `taxable`, `shipping` ou `none`.
	 * @param string $classe          La classe de taxe du produit ; la chaîne vide
	 *                                est la classe standard de WooCommerce.
	 * @param array  $taux_par_classe Table `classe => taux`, résolue une fois pour
	 *                                le pays de la boutique.
	 * @param float  $taux_declare    Le taux déclaré par le marchand, employé quand
	 *                                la boutique n'a pas de table exploitable.
	 * @return array{0: float, 1: ?string} Le taux, et un code de note ou null.
	 */
	public static function resoudre( $taxe_active, $statut_taxe, $classe, array $taux_par_classe, $taux_declare ) {
		// Un produit explicitement non taxable n'est pas un produit dont on
		// ignore le taux : c'est un produit à zéro, et c'est une information.
		if ( 'none' === $statut_taxe ) {
			return array( 0.0, null );
		}

		if ( ! $taxe_active ) {
			return array( self::borner( $taux_declare ), null );
		}

		$classe = (string) $classe;
		if ( array_key_exists( $classe, $taux_par_classe ) && null !== $taux_par_classe[ $classe ] ) {
			return array( self::borner( $taux_par_classe[ $classe ] ), null );
		}

		// La taxe est active mais cette classe n'a aucun taux pour le pays de la
		// boutique. Envoyer 0 exonérerait le produit en silence ; on retient donc
		// le taux déclaré et on le dit.
		return array( self::borner( $taux_declare ), 'TAUX_INTROUVABLE' );
	}

	/**
	 * Ramène un taux dans ce que Felar accepte : de 0 à 100, deux décimales.
	 *
	 * Un taux négatif ou à 120 ferait refuser la ligne entière pour une valeur
	 * que personne n'a saisie volontairement.
	 */
	public static function borner( $taux ) {
		$taux = is_numeric( $taux ) ? (float) $taux : 0.0;
		if ( $taux < 0 ) {
			$taux = 0.0;
		}
		if ( $taux > 100 ) {
			$taux = 100.0;
		}
		return round( $taux, 2 );
	}

	/**
	 * La table `classe => taux` pour le pays de la boutique.
	 *
	 * Somme les taux trouvés : une classe peut en porter plusieurs (taxe d'État
	 * plus taxe locale), et Felar n'en veut qu'un.
	 *
	 * @return array
	 */
	public static function table_woocommerce() {
		$table = array();
		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() || ! class_exists( 'WC_Tax' ) ) {
			return $table;
		}

		$pays    = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
		$classes = array_merge( array( '' ), WC_Tax::get_tax_class_slugs() );

		foreach ( $classes as $classe ) {
			$trouves = WC_Tax::find_rates(
				array(
					'country'   => isset( $pays['country'] ) ? $pays['country'] : '',
					'state'     => isset( $pays['state'] ) ? $pays['state'] : '',
					'tax_class' => $classe,
				)
			);
			if ( empty( $trouves ) ) {
				continue;
			}
			$somme = 0.0;
			foreach ( $trouves as $regle ) {
				if ( isset( $regle['rate'] ) ) {
					$somme += (float) $regle['rate'];
				}
			}
			$table[ (string) $classe ] = round( $somme, 2 );
		}

		return $table;
	}

	private function __construct() {
	}
}
