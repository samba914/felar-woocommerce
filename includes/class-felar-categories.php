<?php
/**
 * Une seule catégorie, la plus profonde.
 *
 * WooCommerce autorise plusieurs catégories par produit, Felar en porte une.
 * On garde **la plus profonde** : « Maroquinerie > Sacs à main » décrit
 * l'article là où « Promotions » ne décrit qu'une mise en avant passagère. Le
 * choix est écrit au rapport, parce qu'un marchand qui ne retrouve pas sa
 * catégorie transversale doit pouvoir comprendre pourquoi.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Categories {

	/**
	 * Retient un chemin parmi plusieurs, et le ramène aux bornes de Felar.
	 *
	 * @param array $chemins Liste de chemins, chacun de la racine vers la feuille.
	 * @return array{chemin: array, notes: array} Chemin retenu (vide si aucun) et
	 *                                            codes de notes à porter au rapport.
	 */
	public static function retenir( array $chemins ) {
		$notes   = array();
		$propres = array();

		foreach ( $chemins as $chemin ) {
			$niveaux = array();
			foreach ( (array) $chemin as $niveau ) {
				$niveau = trim( (string) $niveau );
				if ( '' !== $niveau ) {
					$niveaux[] = Felar_Contrat::couper( $niveau, 120 );
				}
			}
			if ( ! empty( $niveaux ) ) {
				$propres[] = $niveaux;
			}
		}

		if ( empty( $propres ) ) {
			return array(
				'chemin' => array(),
				'notes'  => $notes,
			);
		}

		if ( count( $propres ) > 1 ) {
			$notes[] = 'CATEGORIE_MULTIPLE';
		}

		// Le plus profond gagne. À profondeur égale, l'ordre alphabétique de la
		// feuille tranche : sans cela, deux imports du même catalogue pourraient
		// ranger le même article ailleurs, ce qui ressemble à un défaut.
		usort(
			$propres,
			function ( $a, $b ) {
				$ecart = count( $b ) - count( $a );
				if ( 0 !== $ecart ) {
					return $ecart;
				}
				return strcmp( end( $a ), end( $b ) );
			}
		);

		$retenu = $propres[0];

		// Trop profond pour Felar : on garde les niveaux les plus précis. Couper
		// par la fin renverrait l'article dans une catégorie générale, donc à un
		// endroit où le marchand ne le chercherait pas.
		if ( count( $retenu ) > Felar_Contrat::NIVEAUX_CATEGORIE_MAX ) {
			$retenu  = array_slice( $retenu, - Felar_Contrat::NIVEAUX_CATEGORIE_MAX );
			$notes[] = 'CATEGORIE_TRONQUEE';
		}

		return array(
			'chemin' => $retenu,
			'notes'  => $notes,
		);
	}

	private function __construct() {
	}
}
