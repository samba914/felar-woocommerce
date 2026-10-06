<?php
/**
 * Le nettoyage des refus affichés au marchand.
 *
 * <h2>Pourquoi ces cas existent</h2>
 * Un refus qui ne peut jamais disparaître transforme l'écran des commandes en
 * alarme permanente. Une panne d'une heure — une clé révoquée, Felar
 * indisponible — laissait un compteur rouge et une ligne d'alerte à vie, et un
 * écran qui ne redevient jamais propre cesse d'être lu. C'est précisément celui
 * dont le rôle est de dire quand quelque chose ne va pas.
 *
 * @package Felar_Connect
 */

use PHPUnit\Framework\TestCase;

class RefusTest extends TestCase {

	private function etat( array $refus, $compteur ) {
		return array(
			'compteurs' => array( 'refusees' => $compteur, 'envoyees' => 0 ),
			'refus'     => $refus,
		);
	}

	private function refus( $commande, $bloquant = true ) {
		return array(
			'commande' => $commande,
			'code'     => 'ETAT',
			'message'  => 'Message',
			'bloquant' => $bloquant,
			'quand'    => 1791157873,
		);
	}

	public function test_une_commande_qui_passe_efface_son_refus() {
		$etat = $this->etat( array( $this->refus( 28 ) ), 1 );

		$apres = Felar_Commandes::sans_les_refus_de( $etat, 28 );

		$this->assertSame( array(), $apres['refus'] );
		$this->assertSame( 0, $apres['compteurs']['refusees'] );
	}

	public function test_les_refus_des_autres_commandes_restent() {
		// Sans cette précision, une seule commande qui passe effacerait les alertes
		// de toutes les autres, et le marchand perdrait ce qu'il doit regarder.
		$etat = $this->etat( array( $this->refus( 28 ), $this->refus( 31 ) ), 2 );

		$apres = Felar_Commandes::sans_les_refus_de( $etat, 28 );

		$this->assertCount( 1, $apres['refus'] );
		$this->assertSame( 31, $apres['refus'][0]['commande'] );
		$this->assertSame( 1, $apres['compteurs']['refusees'] );
	}

	public function test_une_simple_remarque_ne_sefface_pas() {
		// « Cet article n'était pas encore importé » décrit la commande telle
		// qu'elle est ENTRÉE. C'est vrai après coup, et le marchand doit le voir.
		$etat = $this->etat( array( $this->refus( 28, false ) ), 0 );

		$apres = Felar_Commandes::sans_les_refus_de( $etat, 28 );

		$this->assertCount( 1, $apres['refus'] );
		$this->assertSame( 0, $apres['compteurs']['refusees'] );
	}

	public function test_le_compteur_ne_passe_jamais_sous_zero() {
		// Les refus sont coupés à vingt pour ne pas remplir la base : le compteur
		// peut donc être plus bas que l'histoire réelle, et une soustraction
		// naïve afficherait « -3 lignes refusées ».
		$etat = $this->etat( array( $this->refus( 28 ), $this->refus( 28 ) ), 1 );

		$apres = Felar_Commandes::sans_les_refus_de( $etat, 28 );

		$this->assertSame( 0, $apres['compteurs']['refusees'] );
	}

	public function test_un_etat_neuf_ne_leve_pas() {
		$apres = Felar_Commandes::sans_les_refus_de(
			array( 'compteurs' => array( 'refusees' => 0 ) ), 28 );

		$this->assertSame( array(), $apres['refus'] );
	}
}
