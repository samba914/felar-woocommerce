<?php
/**
 * Une réponse HTTP, ou l'absence de réponse.
 *
 * Une panne de réseau et un refus du serveur ne se traitent pas de la même
 * façon : la première se rejoue, le second se corrige. Les confondre dans un
 * `false` ferait réessayer indéfiniment un import qu'une clé révoquée condamne.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Reponse {

	/** @var int Le statut HTTP, ou 0 si l'appel n'a pas abouti. */
	private $statut;

	/** @var array En-têtes, clés en minuscules. */
	private $entetes;

	/** @var string Le corps brut. */
	private $corps;

	/** @var string|null La panne de transport, s'il y en a une. */
	private $panne;

	/**
	 * @param int         $statut  Statut HTTP, 0 si l'appel n'a pas abouti.
	 * @param array       $entetes En-têtes reçus.
	 * @param string      $corps   Corps brut.
	 * @param string|null $panne   Message de panne de transport.
	 */
	public function __construct( $statut, array $entetes = array(), $corps = '', $panne = null ) {
		$this->statut  = (int) $statut;
		$this->entetes = array();
		foreach ( $entetes as $nom => $valeur ) {
			$this->entetes[ strtolower( (string) $nom ) ] = is_array( $valeur ) ? reset( $valeur ) : $valeur;
		}
		$this->corps = (string) $corps;
		$this->panne = $panne;
	}

	/** Une panne de transport : rien n'a été reçu. */
	public static function panne( $message ) {
		return new self( 0, array(), '', $message );
	}

	public function statut() {
		return $this->statut;
	}

	public function corps() {
		return $this->corps;
	}

	public function entete( $nom ) {
		$nom = strtolower( (string) $nom );
		return isset( $this->entetes[ $nom ] ) ? $this->entetes[ $nom ] : null;
	}

	/** Vrai quand rien n'a été reçu : coupure, DNS, délai dépassé. */
	public function injoignable() {
		return null !== $this->panne || 0 === $this->statut;
	}

	public function message_de_panne() {
		return $this->panne;
	}

	/**
	 * Le corps décodé, ou un tableau vide.
	 *
	 * Un serveur peut répondre du HTML — un intermédiaire, une page de
	 * maintenance — là où on attend du JSON. Rendre un tableau vide évite une
	 * erreur fatale au milieu d'un import ; c'est au statut de dire si l'appel a
	 * réussi, pas à la présence de JSON.
	 */
	public function json() {
		if ( '' === trim( $this->corps ) ) {
			return array();
		}
		$decode = json_decode( $this->corps, true );
		return is_array( $decode ) ? $decode : array();
	}
}
