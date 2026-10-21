## Course Manager, version MBN GE ##

Ce dépôt est une version dérivée du plugin Course Manager, créé et maintenu par Olivier Valentin. Le plugin d'origine, sa conception et ses fonctionnalités de départ sont son travail. Son dépôt de référence se trouve sur GitHub, sous le nom moodle-report_coursemanager, et contient la documentation complète du plugin.

Nous avons repris ce plugin pour nos plateformes Moodle du Grand-Est, dans une perspective de migration vers Eléa. 
Nous avons stabilisé en premier lieu le fonctionnement du plugin, en corrigeant certains points, et ajouté de nouvelles fonctionnalités. 
Ce plugin s'appuie sur Claude Code en très grande partie pour la partie développement, cela nous a permis de gagner en rapidité devant les échéances.


## Organisation du dépôt ##

Le dépôt contient trois branches principales.

- La branche main est une copie exacte du plugin d'Olivier Valentin, dans sa version de juin 2026. Nous n'y avons rien modifié. Elle sert de point de comparaison : tout ce qui diffère de main est notre travail.
- La branche stabilisation reprend main et y ajoute uniquement des corrections et des améliorations de performance. Elle ne change pas le fonctionnement du plugin pour les enseignants, à l'exception de nouvelles colonnes qui affichent mieux le poids des cours. C'est la version à choisir pour avoir le plugin d'origine, corrigé et plus rapide.
- La branche ameliorations reprend stabilisation et y ajoute les nouvelles fonctionnalités, regroupées dans un nouvel onglet du tableau de bord des enseignants, « Téléchargements et exports ». C'est la version à choisir pour avoir toutes nos fonctionnalités. Elle est encore en phase de test.

Chaque modification a d'abord été faite dans une branche qui lui est propre, puis versée dans stabilisation ou dans ameliorations. Ces branches individuelles sont conservées.

Le plugin nécessite Moodle 4.5 ou une version plus récente, et fonctionne aussi avec Moodle 5.1.


## Le tableau de bord des enseignants ##

Le tableau de bord présente désormais deux tableaux, dans deux onglets séparés.


### Premier tableau : les rapports sur les cours. ###

C'est le tableau d'origine du plugin, amélioré dans la branche stabilisation. Il présente les cours de l'enseignant. Les informations sont calculées chaque nuit. L'administrateur choisit les colonnes affichées.

On y trouve le nom et l'état du cours (visible ou caché), puis trois mesures de poids. La taille sur le disque compte tous les fichiers du cours, y compris les travaux des étudiants, les sauvegardes et la corbeille. Le poids du cours est la même mesure sans les sauvegardes : c'est lui qui déclenche l'alerte « cours lourd ». Le poids de la sauvegarde estime la taille d'une sauvegarde du cours sans les données des étudiants.

Viennent ensuite la comparaison avec la médiane et la moyenne de la plateforme, le nombre de cohortes, d'étudiants et d'enseignants inscrits, les recommandations (cours lourd, vide, sans étudiant, sans visite, avec des devoirs d'étudiants désinscrits) et les actions rapides (mise à la corbeille, fichiers du cours, réinitialisation, cohortes, paramètres).

Toutes les colonnes peuvent être triées, et des boutons de filtre permettent d'afficher les cours concernés par un rapport précis.


### Second tableau : les téléchargements et exports ###

Ce tableau n'existe que dans la branche ameliorations. Il aide les enseignants à préparer le transfert de leurs cours vers une autre plateforme. L'administrateur peut l'activer ou non, et choisit la plupart des colonnes affichées.

- La colonne Alias signale les fichiers qui ne sont que des liens vers d'autres fichiers et qui seraient perdus lors d'une sauvegarde. Une croix rouge et un lien « Corriger » mènent à une page qui transforme ces liens en vrais fichiers, avec possibilité d'annuler. Une coche verte indique qu'il n'y a rien à corriger.
- La colonne Wiris concerne les formules mathématiques écrites avec l'outil Wiris, qui ne sont pas lisibles sur toutes les plateformes. Un lien « Vérifier » ouvre une page qui recherche ces formules dans le cours et les convertit au format LaTeX, avec possibilité d'annuler. Après une vérification, la colonne affiche une coche verte s'il ne reste aucune formule à convertir, ou une croix rouge dans le cas contraire, avec la date de la vérification et un lien pour vérifier à nouveau.
- La colonne Pertes potentielles signale les activités et les blocs du cours qui n'existent pas sur la plateforme de destination. L'administrateur déclare ces éléments dans les réglages du plugin, avec le nom qu'il souhaite montrer aux enseignants. La colonne indique, pour chaque élément présent dans le cours, son nom et son nombre d'utilisations.
- La colonne Dernière sauvegarde indique la date de la dernière sauvegarde faite depuis ce tableau.
- La colonne Téléchargement présente, pour chaque archive de sauvegarde, son poids et un bouton de téléchargement. Le poids s'affiche en rouge au-dessus du seuil de découpage fixé par l'administrateur, en vert en dessous. Sous les archives, la date du dernier téléchargement et le nom de la personne qui l'a fait.
- La colonne Action lance une sauvegarde du cours en un clic, avec les réglages de la plateforme. Les cours trop lourds sont automatiquement découpés en plusieurs archives. En cas d'échec, l'enseignant peut relancer la sauvegarde ou passer à une sauvegarde manuelle.
- La colonne Progression, facultative, montre l'avancement des sauvegardes en cours.
- La colonne Sauvegarde terminée est une case que l'enseignant coche lorsqu'il a téléchargé et vérifié la sauvegarde de son cours. Elle est commune à tous les enseignants du cours et se décoche automatiquement si une nouvelle sauvegarde est lancée. Cette colonne est toujours affichée. Des boutons de filtre permettent d'afficher tous les cours, les cours terminés ou les cours restant à traiter.
- Un texte au-dessus du tableau rappelle les vérifications à faire avant d'exporter un cours.


## Ce que contient la branche stabilisation ##

- Les tâches de calcul nocturnes démarrent à une minute choisie au hasard, pour éviter que toutes les plateformes ne les lancent au même instant. Elles ne s'interrompent plus brutalement lorsqu'un réglage est manquant.
- La page des fichiers du cours prend en compte les travaux remis dans tous les devoirs, ainsi que les fichiers lourds et les vidéos de toutes les activités. Elle n'affiche plus de nom erroné pour les fichiers propres à Moodle, et les fichiers liés aux notes y sont libellés « Notes ».
- Le tableau des rapports a gagné le tri sur toutes les colonnes, un message lorsqu'un filtre ne donne aucun résultat, et un filtre « cours lourd » qui tient compte du seuil en vigueur.
- Le calcul du poids des cours a été corrigé : les poids de plus de 1000 Mo étaient mal lus. Le poids est désormais présenté sous trois formes, décrites plus haut, et l'alerte « cours lourd » ne tient plus compte des sauvegardes.
- Le plugin s'affiche correctement avec les thèmes de Moodle 4.5 comme avec ceux de Moodle 5.
- Le courrier envoyé aux enseignants a été revu : destinataires mieux ciblés, envois tracés, mise en forme soignée, envoi le 1er et le 15 de chaque mois, et seuil de poids à jour.
- Les tâches nocturnes ont été allégées pour les plateformes comptant des milliers de cours : la table des rapports est désormais indexée, les trois poids d'un cours sont calculés en une seule lecture, les rapports ne sont réécrits que lorsqu'ils changent, les visites sont comptées en une seule interrogation par cours au lieu d'une par inscrit, et seules les informations utiles des cours sont lues. Deux erreurs de la recherche des remises orphelines (travaux d’étudiants désinscrits) ont aussi été corrigées : le nettoyage de ces rapports dépendait du mauvais réglage, et la recherche s’arrêtait sur un devoir dont l’activité n’existait plus.
- Enfin, la configuration du plugin peut être exportée et importée à l'aide d'une commande sur le serveur. Cela permet de copier une configuration d'une plateforme à une autre, ou de corriger un réglage mal défini, comme la catégorie corbeille, sur des centaines de plateformes à la fois. Le mode d'emploi figure en bas de la page des réglages du plugin.

## Ce que contient la branche ameliorations ##

- Elle contient tout ce qui précède, plus l'onglet « Téléchargements et exports » décrit plus haut, avec ses outils : la sauvegarde en un clic et le découpage des cours lourds, le suivi des téléchargements, la correction des alias de fichiers, la conversion des formules Wiris, le signalement des activités absentes de la plateforme de destination et le suivi des sauvegardes terminées.
- L'outil de conversion des formules Wiris est adapté d'un plugin séparé, wiris2tex, intégré à Course Manager.


## Pour reprendre une fonctionnalité ##

- Chaque modification se trouve dans sa propre branche, et chaque étape est expliquée

- Les corrections de la branche stabilisation portent sur le code d'origine. La plupart sont courtes et indépendantes les unes des autres : elles peuvent être reprises séparément, ou proposées une par une au dépôt d'origine.

- Les nouvelles fonctionnalités de la branche améliorations reposent toutes sur le nouvel onglet « Téléchargements et exports ». Reprendre l'une d'elles suppose de reprendre aussi cet onglet, ou de l'adapter.
