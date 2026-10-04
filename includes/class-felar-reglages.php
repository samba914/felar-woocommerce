<?php
/**
 * Les réglages, et ce que la boutique dit d'elle-même.
 *
 * <h2>La clé vit dans les options WordPress</h2>
 * Donc visible d'un administrateur du site — ce qui est acceptable, puisque c'est
 * sa clé. Ce qui ne l'est pas : qu'elle apparaisse dans un journal, dans un
 * message d'erreur, ou dans le code source d'une page. Les trois sont traités
 * ici et dans {@see Felar_Journal}.
 *
 * <h2>Deux réglages de WooCommerce commandent tout l'import</h2>
 * « prix saisis taxe comprise » et « taxe activée ». Ils se <b>lisent</b> : les
 * deviner à partir du premier produit inverse la TVA sur tout un catalogue, et
 * rien ne le signale avant la première facture.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Reglages {

	const OPTION = 'felar_connect_reglages';

	/** @var array|null Mémoire de la lecture, pour ne pas relire l'option à chaque appel. */
	private $valeurs = null;

	/** Les valeurs par défaut, et le sens de chacune. */
	public static function defauts() {
		return array(
			// La clé de connecteur, telle que Felar l'a révélée une seule fois.
			'cle'              => '',
			// L'adresse du serveur. Modifiable pour une recette sur tunnel ; c'est
			// aussi pourquoi elle est affichée : un marchand qui l'a changée par
			// mégarde doit pouvoir le voir.
			'serveur'          => Felar_Contrat::BASE_DEFAUT,
			// Le taux employé quand WooCommerce n'a pas de taxe, ou pas de taux
			// pour le pays. Zéro est une réponse valable, et fréquente.
			'taux_declare'     => 0,
			// Les brouillons restent chez eux par défaut : le marchand les a mis en
			// brouillon pour une raison.
			'avec_brouillons'  => false,
			// Réécrire dans WooCommerce les UGS que Felar a fabriquées. Proposé,
			// jamais silencieux : c'est une écriture chez le marchand.
			'ecrire_references' => false,
			// Ce que le dernier appairage a appris, pour l'afficher sans rappeler
			// Felar à chaque chargement d'écran.
			'compte'           => '',
			'devise'           => '',
			'decimales'        => 0,
			'mode'             => '',
			'module_actif'     => false,
			'appairage'        => 0,
		);
	}

	/** Les valeurs enregistrées, complétées par les défauts. */
	public function tout() {
		if ( null === $this->valeurs ) {
			$enregistrees  = get_option( self::OPTION, array() );
			$this->valeurs = array_merge( self::defauts(), is_array( $enregistrees ) ? $enregistrees : array() );
		}
		return $this->valeurs;
	}

	/** Une valeur. */
	public function lire( $nom ) {
		$tout = $this->tout();
		return isset( $tout[ $nom ] ) ? $tout[ $nom ] : null;
	}

	/** Enregistre un sous-ensemble de valeurs. */
	public function ecrire( array $valeurs ) {
		$tout = array_merge( $this->tout(), $valeurs );
		// Seules les clés connues sont conservées : une option qui accueille
		// n'importe quoi devient un dépotoir qu'on n'ose plus nettoyer.
		$propre = array();
		foreach ( self::defauts() as $nom => $defaut ) {
			$propre[ $nom ] = isset( $tout[ $nom ] ) ? $tout[ $nom ] : $defaut;
		}
		$this->valeurs = $propre;
		update_option( self::OPTION, $propre, false );
	}

	/** La clé, ou la chaîne vide. */
	public function cle() {
		return trim( (string) $this->lire( 'cle' ) );
	}

	/** Vrai si une clé est en place et a la forme attendue. */
	public function branchee() {
		return 'inconnu' !== Felar_Contrat::mode_de_la_cle( $this->cle() );
	}

	/** Vrai en mode essai : le serveur valide tout et n'écrit rien. */
	public function en_essai() {
		return 'test' === Felar_Contrat::mode_de_la_cle( $this->cle() );
	}

	/** L'adresse du serveur, nettoyée. */
	public function serveur() {
		$adresse = trim( (string) $this->lire( 'serveur' ) );
		if ( '' === $adresse ) {
			$adresse = Felar_Contrat::BASE_DEFAUT;
		}
		return rtrim( $adresse, '/' );
	}

	/**
	 * Ce que WooCommerce dit de la boutique.
	 *
	 * Lu à chaque fois, jamais mis en cache : un marchand qui corrige son réglage
	 * de TVA doit voir l'effet au rapport suivant, pas au bout d'une heure.
	 */
	public function boutique() {
		return array(
			'devise'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'prix_ttc'     => function_exists( 'wc_prices_include_tax' ) ? wc_prices_include_tax() : false,
			'taxe_active'  => function_exists( 'wc_tax_enabled' ) ? wc_tax_enabled() : false,
			'pays'         => function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array(),
			'unite_poids'  => get_option( 'woocommerce_weight_unit', 'kg' ),
			'taux'         => Felar_Taxes::table_woocommerce(),
			'categorie'    => $this->categorie_par_defaut(),
		);
	}

	/**
	 * La catégorie où WooCommerce range ce qu'on ne classe pas.
	 *
	 * Felar refuse une fiche neuve sans catégorie, et il a raison : chez lui, un
	 * article est toujours rangé quelque part. Il faut donc une réponse pour les
	 * articles que le marchand n'a pas classés — et c'est la boutique qui la donne,
	 * pas nous. En inventer une ajouterait à son catalogue un rangement qu'il n'a
	 * pas voulu et dont il découvrirait l'existence à l'écran.
	 */
	private function categorie_par_defaut() {
		$terme = get_option( 'default_product_cat' );
		if ( $terme ) {
			$objet = get_term( (int) $terme, 'product_cat' );
			if ( $objet && ! is_wp_error( $objet ) ) {
				return $objet->name;
			}
		}
		return 'Non classé';
	}

	/**
	 * Le convertisseur, monté avec les réglages du moment.
	 *
	 * Un seul endroit construit le convertisseur : l'analyse et l'import doivent
	 * voir exactement les mêmes règles, sans quoi le rapport promettrait un
	 * résultat que l'envoi ne tiendrait pas.
	 */
	public function convertisseur() {
		$boutique = $this->boutique();
		$devise   = (string) $this->lire( 'devise' );
		if ( '' === $devise ) {
			$devise = $boutique['devise'];
		}

		return new Felar_Convertisseur(
			$devise,
			$boutique['prix_ttc'],
			$boutique['taxe_active'],
			$boutique['taux'],
			(float) $this->lire( 'taux_declare' ),
			$boutique['unite_poids'],
			(bool) $this->lire( 'avec_brouillons' ),
			$boutique['categorie']
		);
	}

	/** Le client HTTP, monté sur le transport de WordPress. */
	public function client() {
		return new Felar_Client( new Felar_Transport_WP(), $this->serveur(), $this->cle() );
	}

	/**
	 * Retient ce que l'appairage a appris.
	 *
	 * La devise en particulier : c'est la seule que Felar accepte en écriture, et
	 * la comparer à celle de WooCommerce permet d'avertir <b>avant</b> l'import
	 * plutôt que de laisser quatre cents lignes se faire refuser une par une.
	 */
	public function retenir_appairage( array $ping ) {
		$compte = isset( $ping['account'] ) ? $ping['account'] : array();
		$devise = isset( $compte['currency'] ) ? $compte['currency'] : array();

		$this->ecrire(
			array(
				'compte'       => isset( $compte['name'] ) ? (string) $compte['name'] : '',
				'devise'       => isset( $devise['code'] ) ? (string) $devise['code'] : '',
				'decimales'    => isset( $devise['decimals'] ) ? (int) $devise['decimals'] : 0,
				'mode'         => isset( $ping['mode'] ) ? (string) $ping['mode'] : '',
				'module_actif' => ! empty( $ping['module']['active'] ),
				'appairage'    => time(),
			)
		);
	}

	/**
	 * L'écart de devise, ou null.
	 *
	 * Felar ne convertit rien — un taux de change appliqué en silence produit des
	 * factures fausses dont personne ne sait laquelle est en cause. Mieux vaut
	 * donc refuser l'import que d'envoyer un catalogue qui sera refusé ligne par
	 * ligne.
	 */
	public function desaccord_de_devise() {
		$felar = strtoupper( (string) $this->lire( 'devise' ) );
		$woo   = strtoupper( (string) $this->boutique()['devise'] );
		if ( '' === $felar || '' === $woo || $felar === $woo ) {
			return null;
		}
		return array(
			'felar' => $felar,
			'woo'   => $woo,
		);
	}

	/** Oublie tout, à la désinstallation. */
	public static function oublier() {
		delete_option( self::OPTION );
	}
}
