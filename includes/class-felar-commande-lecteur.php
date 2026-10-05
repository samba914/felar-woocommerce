<?php
/**
 * Lit une commande WooCommerce, et rien d'autre.
 *
 * <h2>Par l'API objet, jamais en SQL</h2>
 * WooCommerce range désormais les commandes dans ses propres tables (HPOS) chez
 * les marchands qui l'ont activé, et dans les articles chez les autres. Une
 * requête SQL écrite pour l'un casse chez l'autre — et seulement chez lui, donc on
 * l'apprend par un marchand en colère. `wc_get_order` et les accesseurs de l'objet
 * fonctionnent des deux côtés, c'est leur raison d'être.
 *
 * <h2>Les totaux de ligne sont hors taxe, toujours</h2>
 * `get_total()` d'une ligne est le montant <b>après remises et hors taxe</b>,
 * quelle que soit la façon dont le marchand saisit ses prix au catalogue.
 * `get_total_tax()` porte la taxe correspondante. C'est de ces deux chiffres que
 * tout le reste se déduit.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Commande_Lecteur {

	/**
	 * Une commande, en tableau nu.
	 *
	 * @param int $identifiant L'identifiant WooCommerce.
	 * @return array|null Null si la commande a disparu.
	 */
	public function lire( $identifiant ) {
		$commande = wc_get_order( (int) $identifiant );
		if ( ! $commande ) {
			return null;
		}

		return array(
			'id'         => $commande->get_id(),
			'numero'     => $commande->get_order_number(),
			'date'       => $commande->get_date_created() ? $commande->get_date_created()->format( 'c' ) : '',
			'client'     => $this->client( $commande ),
			'lignes'     => $this->lignes( $commande ),
			'livraison'  => $this->livraison( $commande ),
			'total'      => $commande->get_total(),
			'paiement'   => $this->paiement( $commande ),
			'note'       => $commande->get_customer_note(),
			'admin_url'  => $commande->get_edit_order_url(),
			'statut'     => $commande->get_status(),
		);
	}

	/**
	 * Le client : facturation d'abord, livraison en complément.
	 *
	 * L'adresse retenue est celle de <b>livraison</b> quand elle existe — c'est là
	 * que le colis part, et c'est ce dont le marchand a besoin. La facturation ne
	 * sert qu'à l'identité et aux moyens de le joindre.
	 */
	private function client( $commande ) {
		$adresse = trim( $commande->get_shipping_address_1() . ' ' . $commande->get_shipping_address_2() );
		$ville   = $commande->get_shipping_city();
		if ( '' === trim( $adresse ) ) {
			$adresse = trim( $commande->get_billing_address_1() . ' ' . $commande->get_billing_address_2() );
			$ville   = $commande->get_billing_city();
		}

		return array(
			'prenom'     => $commande->get_billing_first_name(),
			'nom'        => $commande->get_billing_last_name(),
			'telephone'  => $commande->get_billing_phone(),
			'email'      => $commande->get_billing_email(),
			'adresse'    => trim( $adresse ),
			'complement' => trim( (string) $ville ),
		);
	}

	/**
	 * Les lignes d'articles.
	 *
	 * L'identifiant envoyé est celui de la <b>variation</b> quand il y en a une :
	 * c'est elle qui est vendable, et c'est elle que Felar connaît comme
	 * déclinaison.
	 */
	private function lignes( $commande ) {
		$lignes = array();

		foreach ( $commande->get_items() as $article ) {
			$variation = (int) $article->get_variation_id();
			$produit   = $variation > 0 ? $variation : (int) $article->get_product_id();
			$objet     = $article->get_product();

			$lignes[] = array(
				'produit'   => $produit,
				'reference' => $objet ? (string) $objet->get_sku() : '',
				'intitule'  => $article->get_name(),
				'quantite'  => $article->get_quantity(),
				'total'     => $article->get_total(),
				'taxe'      => $article->get_total_tax(),
			);
		}

		return $lignes;
	}

	/** Les frais de port, et la façon dont le client récupère sa commande. */
	private function livraison( $commande ) {
		$intitules = array();
		foreach ( $commande->get_shipping_methods() as $methode ) {
			$intitules[] = $methode->get_name();
		}

		return array(
			'intitule' => implode( ', ', $intitules ),
			'montant'  => $commande->get_shipping_total(),
			// La taxe du transport décide de la façon dont il est envoyé : voir
			// Felar_Commande_Convertisseur. Sans elle, le total ne se recompose pas
			// chez les marchands qui taxent la livraison.
			'taxe'     => $commande->get_shipping_tax(),
			'methode'  => $this->methode_de_remise( $commande ),
		);
	}

	/**
	 * Livraison ou retrait.
	 *
	 * WooCommerce n'a pas de drapeau pour cela : le retrait en magasin est une
	 * méthode d'expédition parmi d'autres, dont l'identifiant commence par
	 * `local_pickup`. C'est le seul repère fiable, et il vaut mieux que de deviner
	 * d'après un intitulé que le marchand a pu renommer.
	 */
	private function methode_de_remise( $commande ) {
		foreach ( $commande->get_shipping_methods() as $methode ) {
			if ( 0 === strpos( (string) $methode->get_method_id(), 'local_pickup' ) ) {
				return 'PICKUP';
			}
		}
		return 'DELIVERY';
	}

	/**
	 * L'état du règlement.
	 *
	 * `is_paid()` est vrai pour les états que le marchand a déclarés comme payés —
	 * `processing` et `completed` par défaut, et ce qu'il y a ajouté. S'appuyer
	 * dessus plutôt que sur une liste écrite ici, c'est respecter sa configuration.
	 */
	private function paiement( $commande ) {
		$paye = $commande->is_paid();

		return array(
			'statut'  => $paye ? 'PAID' : 'UNPAID',
			'methode' => $this->mode( $commande ),
			'paye_le' => $commande->get_date_paid() ? $commande->get_date_paid()->format( 'c' ) : '',
			'montant' => $paye ? $commande->get_total() : 0,
		);
	}

	/**
	 * Le mode de paiement, en vingt caractères.
	 *
	 * L'identifiant technique de la passerelle (`cod`, `bacs`, `wave_gateway`) est
	 * plus stable que son titre, que le marchand renomme librement.
	 */
	private function mode( $commande ) {
		$mode = (string) $commande->get_payment_method();
		if ( '' === $mode ) {
			return '';
		}
		return strtoupper( substr( $mode, 0, 20 ) );
	}
}
