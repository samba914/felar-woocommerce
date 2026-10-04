<?php
/**
 * L'analyse du catalogue, par tranches.
 *
 * <h2>Pourquoi par tranches</h2>
 * Quatre mille produits ne se lisent pas dans une requête HTTP : chaque
 * `wc_get_product` charge ses métadonnées, et la trentième seconde arrive avant
 * la fin. L'écran redemande donc une tranche à la fois et affiche l'avancement —
 * ce qui a un second mérite, celui de montrer au marchand que quelque chose se
 * passe.
 *
 * <h2>Pourquoi l'analyse n'écrit rien</h2>
 * Elle ne parle même pas à Felar. C'est une lecture de son propre catalogue, et
 * c'est ce qui permet de la relancer sans conséquence — un rapport qu'on hésite à
 * demander est un rapport que personne ne lit.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Analyse {

	const OPTION = 'felar_connect_analyse';

	/**
	 * Soixante produits par appel.
	 *
	 * Un produit variable à cinquante variations en fait cinquante et une
	 * lectures : la tranche est donc dimensionnée pour le pire cas raisonnable,
	 * pas pour le cas moyen.
	 */
	const PAR_TRANCHE = 60;

	/** @var Felar_Reglages */
	private $reglages;

	/** @var Felar_Lecteur */
	private $lecteur;

	public function __construct( Felar_Reglages $reglages, Felar_Lecteur $lecteur ) {
		$this->reglages = $reglages;
		$this->lecteur  = $lecteur;
	}

	/**
	 * Repart de zéro.
	 *
	 * @return array L'état initial.
	 */
	public function demarrer() {
		$identifiants = $this->lecteur->identifiants();

		$etat = array(
			'demarre' => time(),
			'ids'     => $identifiants,
			'index'   => 0,
			'rapport' => Felar_Rapport::vide(),
			'termine' => empty( $identifiants ),
		);

		$this->enregistrer( $etat );
		return $this->vue( $etat );
	}

	/**
	 * Traite une tranche.
	 *
	 * @return array La vue de l'avancement.
	 */
	public function avancer() {
		$etat = $this->etat_brut();
		if ( null === $etat ) {
			return $this->demarrer();
		}
		if ( ! empty( $etat['termine'] ) ) {
			return $this->vue( $etat );
		}

		$convertisseur = $this->reglages->convertisseur();
		$total         = count( $etat['ids'] );
		$fin           = min( $total, $etat['index'] + self::PAR_TRANCHE );

		for ( $rang = $etat['index']; $rang < $fin; $rang++ ) {
			$brut = $this->lecteur->lire( $etat['ids'][ $rang ] );
			if ( null === $brut ) {
				// Le produit a disparu depuis la constitution de la liste. Ce n'est
				// pas une anomalie : un marchand travaille pendant qu'on analyse.
				continue;
			}
			$etat['rapport'] = Felar_Rapport::ajouter(
				$etat['rapport'],
				$brut['intitule'],
				$convertisseur->convertir( $brut )
			);
		}

		$etat['index']   = $fin;
		$etat['termine'] = $fin >= $total;

		$this->enregistrer( $etat );
		return $this->vue( $etat );
	}

	/** L'état, ou null s'il n'y a pas d'analyse en mémoire. */
	public function etat_brut() {
		$etat = get_option( self::OPTION, null );
		if ( ! is_array( $etat ) || ! isset( $etat['ids'] ) ) {
			return null;
		}
		return $etat;
	}

	/** La vue de l'analyse en cours, ou null. */
	public function etat() {
		$etat = $this->etat_brut();
		return null === $etat ? null : $this->vue( $etat );
	}

	/**
	 * Ce que l'écran affiche.
	 *
	 * Les exemples et les doublons ne sortent qu'à la fin : en cours de route, un
	 * doublon pas encore rencontré deux fois n'en est pas un, et l'afficher
	 * inquiéterait pour rien.
	 */
	private function vue( array $etat ) {
		$vue = array(
			'termine'     => ! empty( $etat['termine'] ),
			'lus'         => (int) $etat['index'],
			'total'       => count( $etat['ids'] ),
			'resume'      => Felar_Rapport::resume( $etat['rapport'] ),
			'demarre'     => (int) $etat['demarre'],
			'notes'       => array(),
			'exclusions'  => array(),
			'doublons'    => array(),
		);

		if ( $vue['termine'] ) {
			$vue['notes']      = $this->avec_phrases( $etat['rapport']['notes'], Felar_Notes::decisions() );
			$vue['exclusions'] = $this->avec_phrases( $etat['rapport']['exclusions'], Felar_Notes::exclusions() );
			$vue['doublons']   = $etat['rapport']['doublons'];
		}

		return $vue;
	}

	/** Attache à chaque cas sa phrase, triés du plus fréquent au plus rare. */
	private function avec_phrases( array $cas, array $phrases ) {
		$sortie = array();
		foreach ( $cas as $code => $detail ) {
			$sortie[] = array(
				'code'     => $code,
				'nombre'   => $detail['nombre'],
				'exemples' => $detail['exemples'],
				'phrase'   => isset( $phrases[ $code ] ) ? $phrases[ $code ] : $code,
			);
		}
		usort(
			$sortie,
			function ( $a, $b ) {
				return $b['nombre'] - $a['nombre'];
			}
		);
		return $sortie;
	}

	/** Les identifiants retenus, pour l'import. */
	public function identifiants_analyses() {
		$etat = $this->etat_brut();
		return null === $etat ? array() : $etat['ids'];
	}

	/** Les UGS en doublon, que l'import refusera d'envoyer. */
	public function references_refusees() {
		$etat = $this->etat_brut();
		return null === $etat ? array() : Felar_Rapport::references_refusees( $etat['rapport'] );
	}

	private function enregistrer( array $etat ) {
		update_option( self::OPTION, $etat, false );
	}

	/** Oublie l'analyse. */
	public static function oublier() {
		delete_option( self::OPTION );
	}
}
