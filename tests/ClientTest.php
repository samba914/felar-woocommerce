<?php
/**
 * Le client, et les quatre issues d'un appel.
 *
 * <h2>Ce que ces cas protègent</h2>
 * La distinction entre « à rejouer » et « condamné ». La confondre donne l'un des
 * deux défauts classiques d'un connecteur : abandonner un import de quatre mille
 * articles parce que le serveur a hoqueté, ou rejouer sans fin un appel qu'une clé
 * révoquée refusera toujours.
 *
 * Et une garantie qui ne se voit qu'en la cherchant : <b>la clé ne sort jamais dans
 * un message</b>.
 *
 * @package Felar_Connect
 */

use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase {

	const CLE = 'ck_live_7QmK2xR9vT4nB8wZ';

	private function client( array $reponses, &$transport = null ) {
		$transport = new Transport_Factice( $reponses );
		return new Felar_Client( $transport, 'https://api.felar-crm.com', self::CLE );
	}

	private function une_ligne() {
		return array(
			array(
				'externalId'       => '1287',
				'label'            => 'Sac bazin brodé',
				'price'            => array( 'value' => '24500', 'currency' => 'XOF' ),
				'priceIncludesTax' => true,
				'taxRate'          => 18.0,
				'stockTracked'     => true,
			),
		);
	}

	public function test_la_cle_part_dans_len_tete_et_jamais_dans_ladresse() {
		// Une clé en paramètre finit dans les journaux du serveur et dans
		// l'historique du navigateur.
		$client = $this->client( array( new Felar_Reponse( 200, array(), '{"mode":"live"}' ) ), $transport );
		$client->ping();

		$appel = $transport->dernier();
		$this->assertSame( 'https://api.felar-crm.com/api/public/connect/v1/ping', $appel['url'] );
		$this->assertStringNotContainsString( self::CLE, $appel['url'] );
		$this->assertSame( self::CLE, $appel['entetes']['X-Felar-Key'] );
	}

	public function test_un_appairage_reussi_rend_les_donnees() {
		$corps  = '{"mode":"live","account":{"name":"Chez Fatou","currency":{"code":"XOF","decimals":0}},"module":{"active":true}}';
		$client = $this->client( array( new Felar_Reponse( 200, array(), $corps ) ) );

		$resultat = $client->ping();

		$this->assertSame( 'ok', $resultat['issue'] );
		$this->assertSame( 'Chez Fatou', $resultat['donnees']['account']['name'] );
	}

	public function test_une_cle_refusee_est_fatale_et_dit_quoi_faire() {
		$client = $this->client(
			array( new Felar_Reponse( 401, array(), '{"code":"INVALID_API_KEY","message":"Clé inconnue."}' ) )
		);

		$resultat = $client->envoyer_produits( $this->une_ligne() );

		$this->assertSame( 'fatal', $resultat['issue'] );
		$this->assertSame( 'INVALID_API_KEY', $resultat['code'] );
		$this->assertStringContainsString( 'Réglages → Mon site', $resultat['message'] );
	}

	public function test_un_module_inactif_est_fatal_et_nomme_labonnement() {
		$client = $this->client(
			array( new Felar_Reponse( 403, array(), '{"code":"MODULE_REQUIRED","message":"Module requis."}' ) )
		);

		$resultat = $client->envoyer_produits( $this->une_ligne() );

		$this->assertSame( 'fatal', $resultat['issue'] );
		$this->assertStringContainsString( 'abonnement', $resultat['message'] );
	}

	public function test_une_limite_de_debit_demande_dattendre_le_delai_annonce() {
		$client = $this->client(
			array(
				new Felar_Reponse( 429, array( 'Retry-After' => '42' ), '{"code":"RATE_LIMITED"}' ),
			)
		);

		$resultat = $client->envoyer_produits( $this->une_ligne() );

		$this->assertSame( 'attendre', $resultat['issue'] );
		$this->assertSame( 42, $resultat['delai'] );
	}

	public function test_un_retry_after_absurde_est_borne() {
		// Sans borne haute, un en-tête mal formé endormirait la synchronisation pour
		// des heures ; sans borne basse, on repartirait aussitôt se faire refuser.
		$trop_long = $this->client( array( new Felar_Reponse( 429, array( 'Retry-After' => '99999' ), '{}' ) ) );
		$this->assertSame( 600, $trop_long->envoyer_produits( $this->une_ligne() )['delai'] );

		$trop_court = $this->client( array( new Felar_Reponse( 429, array( 'Retry-After' => '0' ), '{}' ) ) );
		$this->assertSame( 5, $trop_court->envoyer_produits( $this->une_ligne() )['delai'] );

		$absent = $this->client( array( new Felar_Reponse( 429, array(), '{}' ) ) );
		$this->assertSame( 60, $absent->envoyer_produits( $this->une_ligne() )['delai'] );
	}

	public function test_une_erreur_serveur_est_a_rejouer_et_non_fatale() {
		$client = $this->client( array( new Felar_Reponse( 500, array(), '{"code":"SERVER_ERROR"}' ) ) );

		$this->assertSame( 'reseau', $client->envoyer_produits( $this->une_ligne() )['issue'] );
	}

	public function test_une_coupure_est_a_rejouer() {
		$client = $this->client( array( Felar_Reponse::panne( 'cURL error 28: timeout' ) ) );

		$resultat = $client->envoyer_produits( $this->une_ligne() );

		$this->assertSame( 'reseau', $resultat['issue'] );
		$this->assertStringContainsString( 'timeout', $resultat['message'] );
	}

	public function test_une_reponse_qui_nest_pas_du_json_ne_casse_rien() {
		// Une page de maintenance d'hébergeur répond du HTML avec un statut 200.
		$client = $this->client( array( new Felar_Reponse( 200, array(), '<html>Maintenance</html>' ) ) );

		$resultat = $client->envoyer_produits( $this->une_ligne() );

		$this->assertSame( 'ok', $resultat['issue'] );
		$this->assertSame( array(), $resultat['donnees'] );
	}

	public function test_un_envoi_sans_image_et_un_envoi_avec_image_nattendent_pas_pareil() {
		// Felar télécharge chaque visuel, le réduit et le range : cent images, c'est
		// plus d'une minute de travail légitime, et un délai trop court produirait un
		// abandon sur un appel qui aboutit.
		$sans = $this->client( array( new Felar_Reponse( 200, array(), '{}' ) ), $transport_sans );
		$sans->envoyer_produits( $this->une_ligne() );
		$this->assertSame( Felar_Client::DELAI_ECRITURE, $transport_sans->dernier()['delai'] );

		$lignes               = $this->une_ligne();
		$lignes[0]['imageUrl'] = 'https://chez-fatou.sn/sac.jpg';
		$avec                  = $this->client( array( new Felar_Reponse( 200, array(), '{}' ) ), $transport_avec );
		$avec->envoyer_produits( $lignes );
		$this->assertSame( Felar_Client::DELAI_ECRITURE_IMAGES, $transport_avec->dernier()['delai'] );
	}

	public function test_un_paquet_vide_ne_part_pas() {
		$client = $this->client( array(), $transport );

		$resultat = $client->envoyer_produits( array() );

		$this->assertSame( 'ok', $resultat['issue'] );
		$this->assertSame( array(), $transport->appels );
	}

	public function test_le_corps_envoye_nomme_la_plateforme() {
		$client = $this->client( array( new Felar_Reponse( 200, array(), '{}' ) ), $transport );
		$client->envoyer_produits( $this->une_ligne() );

		$corps = json_decode( $transport->dernier()['corps'], true );

		$this->assertSame( 'woocommerce', $corps['source'] );
		$this->assertCount( 1, $corps['products'] );
		$this->assertSame( '24500', $corps['products'][0]['price']['value'] );
	}

	public function test_la_cle_masquee_ne_montre_que_le_mode_et_la_fin() {
		$this->assertSame( 'ck_live_…B8wZ', Felar_Client::masquer( self::CLE ) );
		$this->assertSame( 'ck_test_…cdef', Felar_Client::masquer( 'ck_test_0123456789abcdef' ) );
		$this->assertSame( '', Felar_Client::masquer( '' ) );
	}
}
