/*
 * L'écran de Felar Connect, côté navigateur.
 *
 * Deux principes, et ils ont chacun une raison :
 *
 * 1. Rien n'est écrit avec `innerHTML` à partir d'un texte venu du serveur. Les
 *    messages de refus contiennent des intitulés de produits saisis par le
 *    marchand : un nom de produit contenant une balise deviendrait du code exécuté
 *    dans son administration.
 * 2. L'analyse avance par tranches enchaînées, et l'import est *interrogé* plutôt
 *    que suivi. Une tranche qui échoue ne laisse donc pas l'écran figé sur une
 *    promesse.
 */
( function () {
	'use strict';

	var cfg = window.felarConnect || {};

	function poster( action, extra ) {
		var corps = new URLSearchParams();
		corps.append( 'action', action );
		corps.append( 'jeton', cfg.jeton );
		Object.keys( extra || {} ).forEach( function ( nom ) {
			corps.append( nom, extra[ nom ] );
		} );

		return fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: corps.toString()
		} )
			.then( function ( reponse ) {
				return reponse.json().catch( function () {
					return { success: false, data: { message: 'Réponse illisible du site (code ' + reponse.status + ').' } };
				} );
			} )
			.catch( function () {
				return { success: false, data: { message: 'Le site n\'a pas répondu. Vérifiez votre connexion.' } };
			} );
	}

	function vider( element ) {
		while ( element.firstChild ) {
			element.removeChild( element.firstChild );
		}
	}

	function message( texte, ton ) {
		var bloc = document.createElement( 'p' );
		bloc.className = 'felar-message' + ( ton ? ' felar-' + ton : '' );
		bloc.textContent = texte;
		return bloc;
	}

	/*
	 * En français, zéro et un prennent le singulier. « 1 fiches créées » se lit
	 * comme une faute, et sur un écran que le marchand consulte après chaque
	 * import, il la lira souvent.
	 */
	function accord( valeur, singulier, pluriel ) {
		return Math.abs( valeur ) < 2 ? singulier : pluriel;
	}

	function compteur( valeur, singulier, pluriel ) {
		var item = document.createElement( 'li' );
		var fort = document.createElement( 'strong' );
		fort.textContent = String( valeur );
		var texte = document.createElement( 'span' );
		texte.textContent = accord( valeur, singulier, pluriel || singulier );
		item.appendChild( fort );
		item.appendChild( texte );
		return item;
	}

	function barre( fait, total ) {
		var bloc = document.createElement( 'div' );
		bloc.className = 'felar-barre';
		var dedans = document.createElement( 'span' );
		dedans.style.width = ( total > 0 ? Math.round( ( fait / total ) * 100 ) : 0 ) + '%';
		bloc.appendChild( dedans );
		return bloc;
	}

	function listeDeCas( titre, cas ) {
		if ( ! cas || ! cas.length ) {
			return null;
		}
		var section = document.createDocumentFragment();
		var entete = document.createElement( 'h3' );
		entete.textContent = titre;
		section.appendChild( entete );

		var liste = document.createElement( 'ul' );
		liste.className = 'felar-cas';
		cas.forEach( function ( item ) {
			var ligne = document.createElement( 'li' );
			var nombre = document.createElement( 'span' );
			nombre.className = 'felar-cas-nombre';
			nombre.textContent = item.nombre + ' — ';
			var phrase = document.createElement( 'span' );
			phrase.textContent = item.phrase;
			ligne.appendChild( nombre );
			ligne.appendChild( phrase );

			if ( item.exemples && item.exemples.length ) {
				var exemples = document.createElement( 'span' );
				exemples.className = 'felar-cas-exemples';
				exemples.textContent = 'Par exemple : ' + item.exemples.join( ', ' );
				ligne.appendChild( exemples );
			}
			liste.appendChild( ligne );
		} );
		section.appendChild( liste );
		return section;
	}

	/* ----------------------------------------------------------- Connexion */

	var boutonTest = document.getElementById( 'felar-tester' );
	if ( boutonTest ) {
		boutonTest.addEventListener( 'click', function () {
			var zone = document.getElementById( 'felar-resultat-test' );
			boutonTest.disabled = true;
			vider( zone );
			zone.appendChild( message( 'Appel de Felar…' ) );

			poster( 'felar_tester', {} ).then( function ( reponse ) {
				boutonTest.disabled = false;
				vider( zone );

				if ( ! reponse.success ) {
					zone.appendChild( message( reponse.data && reponse.data.message ? reponse.data.message : 'Échec.', 'mal' ) );
					return;
				}

				var d = reponse.data;
				zone.appendChild( message(
					'Connexion établie avec « ' + d.compte +' » (' + d.devise + ', mode ' +
					( 'test' === d.mode ? 'essai' : 'production' ) + ').',
					'bien'
				) );

				if ( ! d.module_actif ) {
					zone.appendChild( message(
						'Votre clé est reconnue, mais votre abonnement Felar ne comprend pas le module Boutique : tout le reste vous sera refusé.',
						'mal'
					) );
				}
				if ( d.desaccord ) {
					zone.appendChild( message(
						'Votre boutique est en ' + d.desaccord.woo + ' et votre compte Felar en ' +
						d.desaccord.felar + '. Felar ne convertit rien : alignez les deux avant d\'importer.',
						'mal'
					) );
				}
				// Les faits affichés sous le tableau viennent du serveur : on recharge
				// pour ne pas maintenir deux vérités à l'écran.
				window.setTimeout( function () {
					window.location.reload();
				}, 1200 );
			} );
		} );
	}

	/* -------------------------------------------------------------- Import */

	var zoneRapport = document.getElementById( 'felar-rapport' );
	var zoneImport = document.getElementById( 'felar-import' );
	var boutonAnalyse = document.getElementById( 'felar-analyser' );
	var boutonImport = document.getElementById( 'felar-importer' );
	var avancement = document.getElementById( 'felar-analyse-avancement' );

	function afficherRapport( vue ) {
		if ( ! zoneRapport || ! vue ) {
			return;
		}
		vider( zoneRapport );

		if ( ! vue.termine ) {
			zoneRapport.appendChild( barre( vue.lus, vue.total ) );
			return;
		}

		var r = vue.resume;
		var liste = document.createElement( 'ul' );
		liste.className = 'felar-compteurs';
		liste.appendChild( compteur( r.produits, 'produit lu', 'produits lus' ) );
		liste.appendChild( compteur( r.a_envoyer, 'ligne à envoyer', 'lignes à envoyer' ) );
		liste.appendChild( compteur( r.modeles, 'fiche à déclinaisons', 'fiches à déclinaisons' ) );
		liste.appendChild( compteur( r.declinaisons, 'déclinaison', 'déclinaisons' ) );
		liste.appendChild( compteur( r.sans_reference, 'sans UGS' ) );
		liste.appendChild( compteur( r.ecartes, 'produit écarté', 'produits écartés' ) );
		liste.appendChild( compteur( r.appels, 'appel prévu', 'appels prévus' ) );
		zoneRapport.appendChild( liste );

		if ( r.references_en_trop > 0 ) {
			zoneRapport.appendChild( message(
				r.references_en_trop +
				accord( r.references_en_trop, ' UGS est portée', ' UGS sont portées' ) +
				' par plusieurs articles : ' + r.lignes_doublons +
				accord( r.lignes_doublons, ' ligne ne sera pas envoyée', ' lignes ne seront pas envoyées' ) +
				'. Corrigez-les dans WooCommerce, puis relancez.',
				'mal'
			) );
		}

		var exclusions = listeDeCas( 'Ce qui ne sera pas importé', vue.exclusions );
		if ( exclusions ) {
			zoneRapport.appendChild( exclusions );
		}
		var decisions = listeDeCas( 'Ce que Felar Connect a décidé', vue.notes );
		if ( decisions ) {
			zoneRapport.appendChild( decisions );
		}

		if ( boutonImport ) {
			boutonImport.disabled = r.a_envoyer < 1;
		}
	}

	function trancheSuivante( reprise ) {
		poster( 'felar_analyser', { reprise: reprise ? '1' : '0' } ).then( function ( reponse ) {
			if ( ! reponse.success ) {
				boutonAnalyse.disabled = false;
				avancement.textContent = '';
				vider( zoneRapport );
				zoneRapport.appendChild( message( reponse.data && reponse.data.message ? reponse.data.message : 'Analyse interrompue.', 'mal' ) );
				return;
			}

			var vue = reponse.data;
			afficherRapport( vue );

			if ( vue.termine ) {
				boutonAnalyse.disabled = false;
				avancement.textContent = vue.total + ' produits analysés.';
				return;
			}

			avancement.textContent = vue.lus + ' / ' + vue.total + ' produits…';
			trancheSuivante( true );
		} );
	}

	if ( boutonAnalyse ) {
		boutonAnalyse.addEventListener( 'click', function () {
			boutonAnalyse.disabled = true;
			if ( boutonImport ) {
				boutonImport.disabled = true;
			}
			avancement.textContent = 'Analyse en cours…';
			vider( zoneRapport );
			trancheSuivante( false );
		} );
	}

	function afficherImport( vue ) {
		if ( ! zoneImport ) {
			return;
		}
		vider( zoneImport );
		if ( ! vue ) {
			return;
		}

		var verdicts = {
			en_cours: 'Import en cours.',
			termine: 'Import terminé.',
			echec: 'Import arrêté sur une erreur.',
			arrete: 'Import arrêté.'
		};
		var ton = 'echec' === vue.statut ? 'mal' : ( 'termine' === vue.statut ? 'bien' : null );
		zoneImport.appendChild( message( verdicts[ vue.statut ] || vue.statut, ton ) );

		if ( vue.message ) {
			zoneImport.appendChild( message( vue.message, 'echec' === vue.statut ? 'mal' : null ) );
		}

		zoneImport.appendChild( barre( vue.lus, vue.total ) );

		var liste = document.createElement( 'ul' );
		liste.className = 'felar-compteurs';
		liste.appendChild( compteur( vue.compteurs.crees, 'fiche créée', 'fiches créées' ) );
		liste.appendChild( compteur( vue.compteurs.majs, 'fiche mise à jour', 'fiches mises à jour' ) );
		liste.appendChild( compteur( vue.compteurs.refusees, 'ligne refusée', 'lignes refusées' ) );
		liste.appendChild( compteur( vue.compteurs.ecartes, 'produit écarté', 'produits écartés' ) );
		liste.appendChild( compteur( vue.compteurs.appels, 'appel à Felar', 'appels à Felar' ) );
		zoneImport.appendChild( liste );

		if ( vue.essai ) {
			zoneImport.appendChild( message(
				'Mode essai : Felar a tout vérifié et n\'a rien enregistré.',
				null
			) );
		}

		if ( 'en_cours' === vue.statut && 0 === vue.en_attente ) {
			zoneImport.appendChild( message(
				'Aucune tâche de fond n\'est en attente alors que l\'import est en cours : la boucle interne de WordPress ne part probablement pas sur ce site. Utilisez « Faire avancer », ou mettez en place un cron système.',
				'mal'
			) );
		}

		var codes = Object.keys( vue.avertissements || {} );
		if ( codes.length ) {
			var entete = document.createElement( 'h3' );
			entete.textContent = 'Avertissements de Felar';
			zoneImport.appendChild( entete );
			var phrases = {
				STOCK_IGNORED: 'Stock ignoré : la fiche existait déjà, et Felar est maître du stock.',
				STOCK_NOT_TRACKED: 'Stock non suivi sur cette fiche.',
				IMAGE_NOT_FETCHED: 'Image non récupérée : adresse injoignable ou refusée.',
				IMAGE_SKIPPED_IN_TEST: 'Image non téléchargée, car la clé est une clé d\'essai.'
			};
			var ul = document.createElement( 'ul' );
			ul.className = 'felar-cas';
			codes.forEach( function ( code ) {
				var li = document.createElement( 'li' );
				var n = document.createElement( 'span' );
				n.className = 'felar-cas-nombre';
				n.textContent = vue.avertissements[ code ] + ' — ';
				var p = document.createElement( 'span' );
				p.textContent = phrases[ code ] || code;
				li.appendChild( n );
				li.appendChild( p );
				ul.appendChild( li );
			} );
			zoneImport.appendChild( ul );
		}

		if ( vue.refus && vue.refus.length ) {
			var titre = document.createElement( 'h3' );
			titre.textContent = accord( vue.refus_total, 'Ligne refusée', 'Lignes refusées' )
				+ ' (' + vue.refus_total + ')';
			zoneImport.appendChild( titre );
			var refus = document.createElement( 'ul' );
			refus.className = 'felar-cas';
			vue.refus.forEach( function ( item ) {
				var li = document.createElement( 'li' );
				li.textContent = 'Produit ' + item.produit + ' — ' + item.message;
				refus.appendChild( li );
			} );
			zoneImport.appendChild( refus );
		}
	}

	var sondage = null;

	function surveiller() {
		if ( sondage ) {
			return;
		}
		sondage = window.setInterval( function () {
			poster( 'felar_etat', {} ).then( function ( reponse ) {
				if ( ! reponse.success ) {
					return;
				}
				afficherImport( reponse.data.import );
				majReferences( reponse.data.references );
				if ( ! reponse.data.import || 'en_cours' !== reponse.data.import.statut ) {
					window.clearInterval( sondage );
					sondage = null;
				}
			} );
		}, 4000 );
	}

	if ( boutonImport ) {
		boutonImport.addEventListener( 'click', function () {
			boutonImport.disabled = true;
			poster( 'felar_importer', {} ).then( function ( reponse ) {
				if ( ! reponse.success ) {
					boutonImport.disabled = false;
					vider( zoneImport );
					zoneImport.appendChild( message( reponse.data && reponse.data.message ? reponse.data.message : 'Import non lancé.', 'mal' ) );
					return;
				}
				afficherImport( reponse.data );
				surveiller();
			} );
		} );
	}

	var boutonPousser = document.getElementById( 'felar-pousser' );
	if ( boutonPousser ) {
		boutonPousser.addEventListener( 'click', function () {
			boutonPousser.disabled = true;
			poster( 'felar_pousser', {} ).then( function ( reponse ) {
				boutonPousser.disabled = false;
				if ( reponse.success ) {
					afficherImport( reponse.data );
					surveiller();
				}
			} );
		} );
	}

	var boutonArret = document.getElementById( 'felar-arreter' );
	if ( boutonArret ) {
		boutonArret.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Arrêter l\'import ? Ce qui est déjà monté reste dans Felar.' ) ) {
				return;
			}
			poster( 'felar_arreter', {} ).then( function ( reponse ) {
				if ( reponse.success ) {
					afficherImport( reponse.data );
				}
			} );
		} );
	}

	/* -------------------------------------------------------- Références */

	var etatReferences = document.getElementById( 'felar-references-etat' );

	function majReferences( combien ) {
		if ( ! etatReferences || 'number' !== typeof combien ) {
			return;
		}
		etatReferences.textContent = combien > 0
			? combien + accord( combien, ' article en attente.', ' articles en attente.' )
			: 'Rien en attente.';
	}

	var boutonReferences = document.getElementById( 'felar-references' );
	if ( boutonReferences ) {
		boutonReferences.addEventListener( 'click', function () {
			boutonReferences.disabled = true;
			etatReferences.textContent = 'Écriture en cours…';

			poster( 'felar_references', {} ).then( function ( reponse ) {
				boutonReferences.disabled = false;
				var zone = document.getElementById( 'felar-references-refus' );
				vider( zone );

				if ( ! reponse.success ) {
					zone.appendChild( message( reponse.data && reponse.data.message ? reponse.data.message : 'Écriture impossible.', 'mal' ) );
					return;
				}

				var d = reponse.data;
				majReferences( d.restantes );
				zone.appendChild( message(
					d.ecrites + accord( d.ecrites, ' UGS écrite', ' UGS écrites' ) + ' dans WooCommerce.',
					'bien'
				) );

				if ( d.refusees && d.refusees.length ) {
					var liste = document.createElement( 'ul' );
					liste.className = 'felar-cas';
					d.refusees.forEach( function ( item ) {
						var li = document.createElement( 'li' );
						li.textContent = item.nom + ' — ' + item.motif;
						liste.appendChild( li );
					} );
					zone.appendChild( liste );
				}
			} );
		} );
	}

	/* -------------------------------------------------- État au chargement */

	function lireInitial( element ) {
		if ( ! element || ! element.dataset.initial ) {
			return null;
		}
		try {
			return JSON.parse( element.dataset.initial );
		} catch ( erreur ) {
			return null;
		}
	}

	afficherRapport( lireInitial( zoneRapport ) );
	var importInitial = lireInitial( zoneImport );
	afficherImport( importInitial );
	if ( importInitial && 'en_cours' === importInitial.statut ) {
		surveiller();
	}
} )();

/* ------------------------------------------------------------------ Stock */
/*
 * Le même esprit que l'import : rien d'écrit par `innerHTML` à partir d'un texte
 * venu du serveur, et l'écran dit ce qui n'a PAS pu être fait — c'est la seule
 * partie que personne ne pense à regarder, et la seule qui explique une dérive
 * découverte à l'inventaire.
 */
( function () {
	'use strict';

	var zone = document.getElementById( 'felar-stock-etat' );
	if ( ! zone ) {
		return;
	}

	var cfg = window.felarConnect || {};

	function poster( action, extra ) {
		var corps = new URLSearchParams();
		corps.append( 'action', action );
		corps.append( 'jeton', cfg.jeton );
		Object.keys( extra || {} ).forEach( function ( nom ) {
			corps.append( nom, extra[ nom ] );
		} );

		return fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: corps.toString()
		} )
			.then( function ( r ) {
				return r.json().catch( function () {
					return { success: false, data: { message: 'Réponse illisible du site (code ' + r.status + ').' } };
				} );
			} )
			.catch( function () {
				return { success: false, data: { message: 'Le site n\'a pas répondu.' } };
			} );
	}

	function vider( e ) {
		while ( e.firstChild ) {
			e.removeChild( e.firstChild );
		}
	}

	function accord( n, singulier, pluriel ) {
		return Math.abs( n ) < 2 ? singulier : pluriel;
	}

	function message( texte, ton ) {
		var p = document.createElement( 'p' );
		p.className = 'felar-message' + ( ton ? ' felar-' + ton : '' );
		p.textContent = texte;
		return p;
	}

	function compteur( valeur, singulier, pluriel ) {
		var li = document.createElement( 'li' );
		var f = document.createElement( 'strong' );
		f.textContent = String( valeur );
		var s = document.createElement( 'span' );
		s.textContent = accord( valeur, singulier, pluriel || singulier );
		li.appendChild( f );
		li.appendChild( s );
		return li;
	}

	function quand( horodatage ) {
		if ( ! horodatage ) {
			return 'jamais';
		}
		return new Date( horodatage * 1000 ).toLocaleString();
	}

	function afficher( vue ) {
		vider( zone );
		if ( ! vue ) {
			return;
		}

		var bascule = document.getElementById( 'felar-stock-bascule' );
		if ( bascule ) {
			bascule.dataset.actif = vue.actif ? '1' : '0';
			bascule.textContent = vue.actif ? 'Arrêter la synchronisation' : 'Activer la synchronisation';
		}

		zone.appendChild( message(
			vue.actif ? 'Synchronisation active.' : 'Synchronisation arrêtée.',
			vue.actif ? 'bien' : null
		) );

		if ( 'erreur' === vue.verdict && vue.message ) {
			zone.appendChild( message( vue.message, 'mal' ) );
		}

		var faits = document.createElement( 'table' );
		faits.className = 'felar-faits';
		[
			[ 'Dernière lecture', quand( vue.dernier ) ],
			[ 'Prochaine prévue', vue.prochain ? quand( vue.prochain ) : 'aucune' ],
			[ 'Reprise depuis', vue.depuis || 'le début du catalogue' ]
		].forEach( function ( ligne ) {
			var tr = document.createElement( 'tr' );
			var th = document.createElement( 'th' );
			th.scope = 'row';
			th.textContent = ligne[ 0 ];
			var td = document.createElement( 'td' );
			td.textContent = ligne[ 1 ];
			tr.appendChild( th );
			tr.appendChild( td );
			faits.appendChild( tr );
		} );
		zone.appendChild( faits );

		var liste = document.createElement( 'ul' );
		liste.className = 'felar-compteurs';
		liste.appendChild( compteur( vue.compteurs.lus, 'article lu', 'articles lus' ) );
		liste.appendChild( compteur( vue.compteurs.ecrits, 'quantité écrite', 'quantités écrites' ) );
		liste.appendChild( compteur( vue.compteurs.inchanges, 'déjà à jour' ) );
		liste.appendChild( compteur( vue.compteurs.ignores, 'laissé de côté', 'laissés de côté' ) );
		zone.appendChild( liste );

		if ( vue.en_cours ) {
			zone.appendChild( message( 'Lecture en cours : il reste des pages à parcourir.' ) );
		}

		if ( vue.cas && vue.cas.length ) {
			var titre = document.createElement( 'h3' );
			titre.textContent = 'Ce qui n\'a pas été écrit, et pourquoi';
			zone.appendChild( titre );

			var ul = document.createElement( 'ul' );
			ul.className = 'felar-cas';
			vue.cas.forEach( function ( cas ) {
				var li = document.createElement( 'li' );
				var n = document.createElement( 'span' );
				n.className = 'felar-cas-nombre';
				n.textContent = cas.nombre + ' — ';
				var p = document.createElement( 'span' );
				p.textContent = cas.phrase;
				li.appendChild( n );
				li.appendChild( p );
				if ( cas.exemples && cas.exemples.length ) {
					var ex = document.createElement( 'span' );
					ex.className = 'felar-cas-exemples';
					ex.textContent = 'Par exemple : ' + cas.exemples.join( ', ' );
					li.appendChild( ex );
				}
				ul.appendChild( li );
			} );
			zone.appendChild( ul );
		}
	}

	function agir( bouton, action, extra, attente ) {
		bouton.disabled = true;
		vider( zone );
		zone.appendChild( message( attente ) );

		poster( action, extra ).then( function ( reponse ) {
			bouton.disabled = false;
			if ( ! reponse.success ) {
				vider( zone );
				zone.appendChild( message(
					reponse.data && reponse.data.message ? reponse.data.message : 'Échec.',
					'mal'
				) );
				return;
			}
			afficher( reponse.data );
		} );
	}

	var bascule = document.getElementById( 'felar-stock-bascule' );
	if ( bascule ) {
		bascule.addEventListener( 'click', function () {
			var allumer = '1' !== bascule.dataset.actif;
			if ( allumer && ! window.confirm(
				'À partir de maintenant, c\'est Felar qui tient le stock : les quantités de votre boutique seront remplacées par les siennes. Continuer ?'
			) ) {
				return;
			}
			agir( bascule, 'felar_stock_actif', { actif: allumer ? '1' : '0' },
				allumer ? 'Mise en route…' : 'Arrêt…' );
		} );
	}

	var maintenant = document.getElementById( 'felar-stock-maintenant' );
	if ( maintenant ) {
		maintenant.addEventListener( 'click', function () {
			agir( maintenant, 'felar_stock_maint', {}, 'Lecture du stock chez Felar…' );
		} );
	}

	var tout = document.getElementById( 'felar-stock-tout' );
	if ( tout ) {
		tout.addEventListener( 'click', function () {
			if ( ! window.confirm( 'Relire tout le catalogue au prochain passage ?' ) ) {
				return;
			}
			agir( tout, 'felar_stock_tout', {}, 'Relecture complète…' );
		} );
	}

	try {
		afficher( JSON.parse( zone.dataset.initial ) );
	} catch ( erreur ) {
		/* Rien à afficher au chargement : les boutons restent utilisables. */
	}
} )();

/* -------------------------------------------------------------- Commandes */
( function () {
	'use strict';

	var zone = document.getElementById( 'felar-cmd-etat' );
	if ( ! zone ) {
		return;
	}

	var cfg = window.felarConnect || {};

	function poster( action, extra ) {
		var corps = new URLSearchParams();
		corps.append( 'action', action );
		corps.append( 'jeton', cfg.jeton );
		Object.keys( extra || {} ).forEach( function ( n ) {
			corps.append( n, extra[ n ] );
		} );
		return fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: corps.toString()
		} )
			.then( function ( r ) {
				return r.json().catch( function () {
					return { success: false, data: { message: 'Réponse illisible (code ' + r.status + ').' } };
				} );
			} )
			.catch( function () {
				return { success: false, data: { message: 'Le site n\'a pas répondu.' } };
			} );
	}

	function vider( e ) {
		while ( e.firstChild ) {
			e.removeChild( e.firstChild );
		}
	}

	function accord( n, s, p ) {
		return Math.abs( n ) < 2 ? s : ( p || s );
	}

	function message( texte, ton ) {
		var p = document.createElement( 'p' );
		p.className = 'felar-message' + ( ton ? ' felar-' + ton : '' );
		p.textContent = texte;
		return p;
	}

	function compteur( valeur, s, p ) {
		var li = document.createElement( 'li' );
		var f = document.createElement( 'strong' );
		f.textContent = String( valeur );
		var t = document.createElement( 'span' );
		t.textContent = accord( valeur, s, p );
		li.appendChild( f );
		li.appendChild( t );
		return li;
	}

	function afficher( vue ) {
		vider( zone );
		if ( ! vue ) {
			return;
		}

		var bascule = document.getElementById( 'felar-cmd-bascule' );
		if ( bascule ) {
			bascule.dataset.actif = vue.actif ? '1' : '0';
			bascule.textContent = vue.actif ? 'Arrêter l\'envoi' : 'Activer l\'envoi';
		}

		zone.appendChild( message(
			vue.actif ? 'Envoi des commandes actif.' : 'Envoi des commandes arrêté.',
			vue.actif ? 'bien' : null
		) );

		if ( 'number' === typeof vue.reprises ) {
			zone.appendChild( message(
				vue.reprises + accord( vue.reprises, ' commande remise en file.', ' commandes remises en file.' )
			) );
		}

		var liste = document.createElement( 'ul' );
		liste.className = 'felar-compteurs';
		liste.appendChild( compteur( vue.compteurs.envoyees, 'commande montée', 'commandes montées' ) );
		liste.appendChild( compteur( vue.compteurs.etats, 'suite annoncée', 'suites annoncées' ) );
		liste.appendChild( compteur( vue.compteurs.doublons, 'déjà connue', 'déjà connues' ) );
		liste.appendChild( compteur( vue.compteurs.refusees, 'non transmise', 'non transmises' ) );
		liste.appendChild( compteur( vue.en_attente, 'en attente' ) );
		zone.appendChild( liste );

		var codes = Object.keys( vue.avertissements || {} );
		if ( codes.length ) {
			var h = document.createElement( 'h3' );
			h.textContent = 'Ce que Felar a signalé';
			zone.appendChild( h );
			var phrases = {
				INSUFFICIENT_STOCK: 'Le stock ne suffisait pas : la vente est enregistrée et le stock régularisé, avec son motif dans le kardex.',
				UNKNOWN_PRODUCT: 'Un article de la commande n\'existe pas dans Felar : la ligne y est conservée hors catalogue.',
				TOTAL_ROUNDED: 'Le total recomposé par Felar s\'écarte un peu du vôtre : vérifiez vos arrondis.'
			};
			var ul = document.createElement( 'ul' );
			ul.className = 'felar-cas';
			codes.forEach( function ( code ) {
				var li = document.createElement( 'li' );
				var n = document.createElement( 'span' );
				n.className = 'felar-cas-nombre';
				n.textContent = vue.avertissements[ code ] + ' — ';
				var p = document.createElement( 'span' );
				p.textContent = phrases[ code ] || code;
				li.appendChild( n );
				li.appendChild( p );
				ul.appendChild( li );
			} );
			zone.appendChild( ul );
		}

		if ( vue.refus && vue.refus.length ) {
			var titre = document.createElement( 'h3' );
			titre.textContent = 'Les dernières commandes à regarder';
			zone.appendChild( titre );
			var ul2 = document.createElement( 'ul' );
			ul2.className = 'felar-cas';
			vue.refus.forEach( function ( r ) {
				var li = document.createElement( 'li' );
				var n = document.createElement( 'span' );
				n.className = 'felar-cas-nombre';
				n.textContent = 'Commande ' + r.commande + ' — ';
				var p = document.createElement( 'span' );
				p.textContent = r.message;
				li.appendChild( n );
				li.appendChild( p );
				ul2.appendChild( li );
			} );
			zone.appendChild( ul2 );
		}
	}

	function agir( bouton, action, extra, attente ) {
		bouton.disabled = true;
		vider( zone );
		zone.appendChild( message( attente ) );
		poster( action, extra ).then( function ( reponse ) {
			bouton.disabled = false;
			if ( ! reponse.success ) {
				vider( zone );
				zone.appendChild( message(
					reponse.data && reponse.data.message ? reponse.data.message : 'Échec.', 'mal'
				) );
				return;
			}
			afficher( reponse.data );
		} );
	}

	var bascule = document.getElementById( 'felar-cmd-bascule' );
	if ( bascule ) {
		bascule.addEventListener( 'click', function () {
			var allumer = '1' !== bascule.dataset.actif;
			agir( bascule, 'felar_cmd_actif', { actif: allumer ? '1' : '0' },
				allumer ? 'Mise en route…' : 'Arrêt…' );
		} );
	}

	var reprise = document.getElementById( 'felar-cmd-reprise' );
	if ( reprise ) {
		reprise.addEventListener( 'click', function () {
			agir( reprise, 'felar_cmd_reprise', {}, 'Remise en file…' );
		} );
	}

	try {
		afficher( JSON.parse( zone.dataset.initial ) );
	} catch ( erreur ) {
		/* Rien à afficher au chargement. */
	}
} )();
