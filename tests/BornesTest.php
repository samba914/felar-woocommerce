<?php
/**
 * Les bornes du contrat, la TVA et les catégories.
 *
 * <h2>Ce que ces cas protègent</h2>
 * La coupe d'un texte accentué — `substr` compte des octets, et couper « brodé »
 * au mauvais endroit rend une chaîne que le serveur refuse comme non-UTF-8 — et le
 * choix de la catégorie, qui doit rester le même d'un import à l'autre.
 *
 * @package Felar_Connect
 */

use PHPUnit\Framework\TestCase;

class BornesTest extends TestCase {

	public function test_une_coupe_ne_casse_pas_un_caractere_accentue() {
		$texte = str_repeat( 'é', 300 );
		$coupe = Felar_Contrat::couper( $texte, 255 );

		$this->assertSame( 255, mb_strlen( $coupe, 'UTF-8' ) );
		$this->assertSame( $coupe, mb_convert_encoding( $coupe, 'UTF-8', 'UTF-8' ) );
	}

	public function test_un_texte_assez_court_nest_pas_touche() {
		$this->assertSame( 'Sac bazin brodé', Felar_Contrat::couper( '  Sac bazin brodé  ', 255 ) );
		$this->assertFalse( Felar_Contrat::trop_long( 'Sac bazin brodé', 255 ) );
		$this->assertTrue( Felar_Contrat::trop_long( str_repeat( 'a', 256 ), 255 ) );
	}

	public function test_le_mode_se_lit_dans_le_prefixe_de_la_cle() {
		// Le dire sans appeler le serveur permet d'avertir le marchand dès qu'il
		// colle la clé : le mode est la première cause de confusion.
		$this->assertSame( 'live', Felar_Contrat::mode_de_la_cle( 'ck_live_abc' ) );
		$this->assertSame( 'test', Felar_Contrat::mode_de_la_cle( 'ck_test_abc' ) );
		$this->assertSame( 'inconnu', Felar_Contrat::mode_de_la_cle( 'sk_live_abc' ) );
		$this->assertSame( 'inconnu', Felar_Contrat::mode_de_la_cle( '' ) );
	}

	public function test_seules_le_https_et_le_bouclage_recoivent_la_cle() {
		// La clé vaut un mot de passe : en clair sur un réseau, elle est donnée à qui
		// écoute. Sur la machine elle-même, il n'y a pas de réseau à écouter.
		$this->assertTrue( Felar_Contrat::adresse_acceptable( 'https://api.felar-crm.com' ) );
		$this->assertTrue( Felar_Contrat::adresse_acceptable( 'http://localhost:8080' ) );
		$this->assertTrue( Felar_Contrat::adresse_acceptable( 'http://127.0.0.1:8080' ) );
		$this->assertTrue( Felar_Contrat::adresse_acceptable( 'http://felar.test' ) );

		$this->assertFalse( Felar_Contrat::adresse_acceptable( 'http://api.felar-crm.com' ) );
		$this->assertFalse( Felar_Contrat::adresse_acceptable( 'http://192.168.1.20:8080' ) );
		$this->assertFalse( Felar_Contrat::adresse_acceptable( 'ftp://felar-crm.com' ) );
		$this->assertFalse( Felar_Contrat::adresse_acceptable( '' ) );

		// Un nom qui *contient* localhost sans en être un ne passe pas : sinon
		// « http://localhost.attaquant.com » recevrait la clé.
		$this->assertFalse( Felar_Contrat::adresse_acceptable( 'http://localhost.attaquant.com' ) );

		$this->assertTrue( Felar_Contrat::adresse_locale( 'http://localhost:8080' ) );
		$this->assertFalse( Felar_Contrat::adresse_locale( 'https://api.felar-crm.com' ) );
	}

	public function test_un_taux_hors_bornes_est_ramene_dans_le_contrat() {
		// Un taux à 120 ferait refuser la ligne entière pour une valeur que personne
		// n'a saisie volontairement.
		$this->assertSame( 0.0, Felar_Taxes::borner( -5 ) );
		$this->assertSame( 100.0, Felar_Taxes::borner( 120 ) );
		$this->assertSame( 18.0, Felar_Taxes::borner( '18' ) );
		$this->assertSame( 0.0, Felar_Taxes::borner( 'pas un nombre' ) );
	}

	public function test_la_resolution_de_la_tva_suit_quatre_cas() {
		$table = array( '' => 18.0, 'reduced-rate' => 10.0 );

		$this->assertSame( array( 18.0, null ), Felar_Taxes::resoudre( true, 'taxable', '', $table, 0 ) );
		$this->assertSame( array( 10.0, null ), Felar_Taxes::resoudre( true, 'taxable', 'reduced-rate', $table, 0 ) );
		$this->assertSame( array( 0.0, null ), Felar_Taxes::resoudre( true, 'none', '', $table, 18 ) );
		$this->assertSame( array( 5.0, null ), Felar_Taxes::resoudre( false, 'taxable', '', $table, 5 ) );
		$this->assertSame(
			array( 7.0, 'TAUX_INTROUVABLE' ),
			Felar_Taxes::resoudre( true, 'taxable', 'zero-rate', $table, 7 )
		);
	}

	public function test_la_categorie_la_plus_profonde_gagne_et_le_choix_est_stable() {
		$resultat = Felar_Categories::retenir(
			array(
				array( 'Promotions' ),
				array( 'Maroquinerie', 'Sacs à main' ),
			)
		);

		$this->assertSame( array( 'Maroquinerie', 'Sacs à main' ), $resultat['chemin'] );
		$this->assertContains( 'CATEGORIE_MULTIPLE', $resultat['notes'] );

		// À profondeur égale, l'ordre alphabétique de la feuille tranche : sans cela,
		// deux imports du même catalogue rangeraient l'article ailleurs, ce qui
		// ressemble à un défaut.
		$egalite = Felar_Categories::retenir( array( array( 'Zèbres' ), array( 'Ananas' ) ) );
		$this->assertSame( array( 'Ananas' ), $egalite['chemin'] );
	}

	public function test_une_arborescence_trop_profonde_garde_les_niveaux_les_plus_precis() {
		// Couper par la fin renverrait l'article dans une catégorie générale, donc à
		// un endroit où le marchand ne le chercherait pas.
		$resultat = Felar_Categories::retenir(
			array( array( 'Un', 'Deux', 'Trois', 'Quatre', 'Cinq' ) )
		);

		$this->assertSame( array( 'Deux', 'Trois', 'Quatre', 'Cinq' ), $resultat['chemin'] );
		$this->assertContains( 'CATEGORIE_TRONQUEE', $resultat['notes'] );
	}

	public function test_aucune_categorie_ne_donne_aucun_chemin() {
		$resultat = Felar_Categories::retenir( array( array(), array( '  ' ) ) );

		$this->assertSame( array(), $resultat['chemin'] );
		$this->assertSame( array(), $resultat['notes'] );
	}

	public function test_chaque_code_de_note_a_une_phrase() {
		// Une note sans phrase s'afficherait en majuscules techniques sur l'écran du
		// marchand.
		$codes = array_merge(
			array_keys( Felar_Notes::decisions() ),
			array_keys( Felar_Notes::exclusions() )
		);

		foreach ( $codes as $code ) {
			$phrase = Felar_Notes::phrase( $code );
			$this->assertNotSame( $code, $phrase, 'Le code ' . $code . ' n\'a pas de phrase.' );
			$this->assertStringEndsWith( '.', $phrase, 'La phrase de ' . $code . ' ne finit pas par un point.' );
		}
	}
}
