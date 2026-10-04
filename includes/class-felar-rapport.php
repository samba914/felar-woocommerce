<?php
/**
 * Le rapport qu'on lit <b>avant</b> de lancer l'import.
 *
 * « 412 produits, 12 sans référence, 38 variables, 3 doublons d'UGS. » Un import
 * qu'on ne peut pas relire avant est un import qu'on annule après — et annuler
 * un import de catalogue, cela veut dire supprimer quatre cents fiches à la main.
 *
 * <h2>Pourquoi un tableau et pas un objet</h2>
 * L'analyse d'un gros catalogue ne tient pas dans une requête HTTP : elle avance
 * par tranches, et son état voyage d'un appel au suivant dans une option
 * WordPress. Un tableau se sérialise sans surprise ; un objet avec des
 * dépendances ne survit pas au passage.
 *
 * <h2>Ce qui est borné, et pourquoi</h2>
 * Les compteurs sont exacts — c'est le but. Les <b>exemples</b> ne retiennent que
 * cinq noms par cas : ce qu'il faut pour comprendre, sans transformer une option
 * en fichier que l'écran ne saurait pas afficher. Les références, elles, sont
 * toutes retenues, parce que c'est le seul moyen de trouver un doublon d'UGS.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Rapport {

	/** Cinq noms par cas : de quoi reconnaître la situation, pas de quoi la dérouler. */
	const EXEMPLES_MAX = 5;

	/** @return array Un rapport vierge. */
	public static function vide() {
		return array(
			'produits'        => 0,
			'lignes'          => 0,
			'simples'         => 0,
			'modeles'         => 0,
			'declinaisons'    => 0,
			'ecartes'         => 0,
			'sans_reference'  => 0,
			'lignes_doublons' => 0,
			'notes'           => array(),
			'exclusions'      => array(),
			'references'      => array(),
			'doublons'        => array(),
		);
	}

	/**
	 * Verse un produit converti dans le rapport.
	 *
	 * @param array  $rapport    L'état courant.
	 * @param string $nom        L'intitulé du produit, pour les exemples.
	 * @param array  $conversion Le résultat de {@see Felar_Convertisseur::convertir()}.
	 * @return array Le nouvel état.
	 */
	public static function ajouter( array $rapport, $nom, array $conversion ) {
		$rapport['produits']++;

		if ( 'ecarte' === $conversion['mode'] ) {
			$rapport['ecartes']++;
			$rapport['exclusions'] = self::compter( $rapport['exclusions'], $conversion['ecarte'], $nom );
			return $rapport;
		}

		if ( 'declinaisons' === $conversion['mode'] ) {
			$rapport['modeles']++;
			$rapport['declinaisons'] += count( $conversion['lignes'] );
		} else {
			$rapport['simples']++;
		}

		foreach ( $conversion['notes'] as $note ) {
			$rapport['notes'] = self::compter( $rapport['notes'], $note, $nom );
		}

		foreach ( $conversion['lignes'] as $ligne ) {
			$rapport['lignes']++;
			if ( ! isset( $ligne['reference'] ) || '' === $ligne['reference'] ) {
				$rapport['sans_reference']++;
				continue;
			}
			$rapport = self::surveiller_reference( $rapport, $ligne['reference'], $ligne['label'] );
		}

		return $rapport;
	}

	/**
	 * Repère les UGS portées par plusieurs articles.
	 *
	 * Cela arrive — un produit dupliqué dans WooCommerce garde l'UGS de l'original
	 * tant qu'on ne la corrige pas. Les fusionner à notre initiative serait une
	 * décision de catalogue, pas une décision d'import : les deux sont écartés et
	 * nommés, et le marchand tranche chez lui.
	 *
	 * La comparaison ignore la casse et les espaces de bord, parce que
	 * « bz-sac-01 » et « BZ-SAC-01 » sont la même étiquette pour un caissier, et
	 * que Felar refuserait la seconde.
	 */
	private static function surveiller_reference( array $rapport, $reference, $intitule ) {
		$cle = self::normaliser( $reference );

		if ( ! isset( $rapport['references'][ $cle ] ) ) {
			$rapport['references'][ $cle ] = $intitule;
			return $rapport;
		}

		if ( ! isset( $rapport['doublons'][ $cle ] ) ) {
			// La première occurrence tombe elle aussi : au moment où on découvre le
			// doublon, elle est déjà comptée comme envoyable.
			$rapport['doublons'][ $cle ] = array( $rapport['references'][ $cle ] );
			$rapport['lignes_doublons']++;
		}
		if ( count( $rapport['doublons'][ $cle ] ) < self::EXEMPLES_MAX ) {
			$rapport['doublons'][ $cle ][] = $intitule;
		}
		$rapport['lignes_doublons']++;

		return $rapport;
	}

	/** La forme sous laquelle on compare deux références. */
	public static function normaliser( $reference ) {
		$reference = trim( (string) $reference );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $reference, 'UTF-8' ) : strtolower( $reference );
	}

	/** Incrémente un cas, en gardant quelques noms en exemple. */
	private static function compter( array $cas, $code, $nom ) {
		if ( ! isset( $cas[ $code ] ) ) {
			$cas[ $code ] = array(
				'nombre'   => 0,
				'exemples' => array(),
			);
		}
		$cas[ $code ]['nombre']++;
		if ( count( $cas[ $code ]['exemples'] ) < self::EXEMPLES_MAX ) {
			$cas[ $code ]['exemples'][] = $nom;
		}
		return $cas;
	}

	/**
	 * Ce qu'on montre au marchand, prêt à afficher.
	 *
	 * `a_envoyer` retire les lignes dont l'UGS est en doublon : ce sont les seules
	 * décidées après coup, une fois le catalogue entier parcouru.
	 *
	 * @param array $rapport L'état complet.
	 * @return array
	 */
	public static function resume( array $rapport ) {
		$doublons = count( $rapport['doublons'] );

		return array(
			'produits'           => $rapport['produits'],
			'lignes'             => $rapport['lignes'],
			'a_envoyer'          => max( 0, $rapport['lignes'] - $rapport['lignes_doublons'] ),
			'simples'            => $rapport['simples'],
			'modeles'            => $rapport['modeles'],
			'declinaisons'       => $rapport['declinaisons'],
			'ecartes'            => $rapport['ecartes'],
			'sans_reference'     => $rapport['sans_reference'],
			'references_en_trop' => $doublons,
			'lignes_doublons'    => $rapport['lignes_doublons'],
			'appels'             => (int) ceil( max( 0, $rapport['lignes'] - $rapport['lignes_doublons'] ) / Felar_Contrat::LIGNES_PAR_APPEL ),
		);
	}

	/**
	 * Les UGS à refuser à l'envoi, en index pour un test en temps constant.
	 *
	 * @return array
	 */
	public static function references_refusees( array $rapport ) {
		$refusees = array();
		foreach ( array_keys( $rapport['doublons'] ) as $cle ) {
			$refusees[ $cle ] = true;
		}
		return $refusees;
	}

	private function __construct() {
	}
}
