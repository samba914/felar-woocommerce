<?php
/**
 * L'amorce des tests.
 *
 * <h2>Pourquoi il n'y a pas de WordPress ici</h2>
 * Les classes éprouvées n'en ont pas besoin : elles reçoivent des tableaux et
 * rendent des tableaux. C'est le point de la séparation entre {@see Felar_Lecteur},
 * qui parle à WooCommerce, et le reste, qui décide. La conséquence est qu'un cas de
 * test coûte trois lignes au lieu d'une installation, et qu'on peut donc en écrire
 * un pour chaque situation pénible du commerce réel.
 *
 * Les deux seules dépendances à WordPress rencontrées dans ce périmètre — la garde
 * `ABSPATH` et `wp_json_encode` — sont remplacées ici.
 *
 * @package Felar_Connect
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * L'équivalent de WordPress : du JSON sans échappement inutile des accents.
	 *
	 * @param mixed $donnees Ce qu'il faut encoder.
	 * @return string
	 */
	function wp_json_encode( $donnees ) {
		return json_encode( $donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}

$felar_racine = dirname( __DIR__ ) . '/includes/';

require_once $felar_racine . 'class-felar-contrat.php';
require_once $felar_racine . 'class-felar-notes.php';
require_once $felar_racine . 'class-felar-taxes.php';
require_once $felar_racine . 'class-felar-categories.php';
require_once $felar_racine . 'class-felar-convertisseur.php';
require_once $felar_racine . 'class-felar-rapport.php';
require_once $felar_racine . 'class-felar-reponse.php';
require_once $felar_racine . 'interface-felar-transport.php';
require_once $felar_racine . 'class-felar-client.php';
require_once $felar_racine . 'class-felar-stock-regles.php';
require_once $felar_racine . 'class-felar-commande-convertisseur.php';
require_once $felar_racine . 'class-felar-propagation.php';
require_once __DIR__ . '/class-transport-factice.php';
require_once __DIR__ . '/class-produits.php';
