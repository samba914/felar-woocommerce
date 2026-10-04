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

	function compteur( valeur, intitule ) {
		var item = document.createElement( 'li' );
		var fort = document.createElement( 'strong' );
		fort.textContent = String( valeur );
		var texte = document.createElement( 'span' );
		texte.textContent = intitule;
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
		liste.appendChild( compteur( r.produits, 'produits lus' ) );
		liste.appendChild( compteur( r.a_envoyer, 'lignes à envoyer' ) );
		liste.appendChild( compteur( r.modeles, 'fiches à déclinaisons' ) );
		liste.appendChild( compteur( r.declinaisons, 'déclinaisons' ) );
		liste.appendChild( compteur( r.sans_reference, 'sans UGS' ) );
		liste.appendChild( compteur( r.ecartes, 'écartés' ) );
		liste.appendChild( compteur( r.appels, 'appels prévus' ) );
		zoneRapport.appendChild( liste );

		if ( r.references_en_trop > 0 ) {
			zoneRapport.appendChild( message(
				r.references_en_trop + ' UGS sont portées par plusieurs articles : ' +
				r.lignes_doublons + ' ligne(s) ne seront pas envoyées. Corrigez-les dans WooCommerce, puis relancez.',
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
		liste.appendChild( compteur( vue.compteurs.crees, 'fiches créées' ) );
		liste.appendChild( compteur( vue.compteurs.majs, 'fiches mises à jour' ) );
		liste.appendChild( compteur( vue.compteurs.refusees, 'lignes refusées' ) );
		liste.appendChild( compteur( vue.compteurs.ecartes, 'produits écartés' ) );
		liste.appendChild( compteur( vue.compteurs.appels, 'appels à Felar' ) );
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
			titre.textContent = 'Lignes refusées (' + vue.refus_total + ')';
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
			? combien + ' article(s) en attente.'
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
				zone.appendChild( message( d.ecrites + ' UGS écrite(s) dans WooCommerce.', 'bien' ) );

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
