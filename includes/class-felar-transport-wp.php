<?php
/**
 * Le transport réel, celui de WordPress.
 *
 * Tout ce qui est propre à WordPress dans les appels sortants tient ici : c'est
 * la seule classe qu'on ne peut pas éprouver hors d'un site, et elle ne contient
 * donc aucune décision.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Transport_WP implements Felar_Transport {

	/**
	 * @param string      $methode 'GET' ou 'POST'.
	 * @param string      $url     L'adresse complète.
	 * @param array       $entetes En-têtes.
	 * @param string|null $corps   Le corps JSON.
	 * @param int         $delai   Le délai d'attente, en secondes.
	 * @return Felar_Reponse
	 */
	public function appeler( $methode, $url, array $entetes, $corps, $delai ) {
		$arguments = array(
			'method'  => $methode,
			'timeout' => (int) $delai,
			'headers' => $entetes,
			// Un connecteur qui suit une redirection vers un autre hôte y enverrait
			// la clé. Felar ne redirige pas ; on ne suit donc rien.
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'Felar-Connect/' . FELAR_CONNECT_VERSION . '; ' . home_url( '/' ),
		);
		if ( null !== $corps ) {
			$arguments['body'] = $corps;
		}

		$reponse = wp_remote_request( $url, $arguments );

		if ( is_wp_error( $reponse ) ) {
			return Felar_Reponse::panne( $reponse->get_error_message() );
		}

		// Selon la version de WordPress, les en-têtes arrivent en dictionnaire
		// insensible à la casse ou en tableau nu. Supposer l'un des deux casse
		// l'extension sur l'autre, et seulement chez les marchands qui l'ont.
		$recus = wp_remote_retrieve_headers( $reponse );
		if ( is_object( $recus ) && method_exists( $recus, 'getAll' ) ) {
			$recus = $recus->getAll();
		}

		return new Felar_Reponse(
			(int) wp_remote_retrieve_response_code( $reponse ),
			is_array( $recus ) ? $recus : array(),
			(string) wp_remote_retrieve_body( $reponse )
		);
	}
}
