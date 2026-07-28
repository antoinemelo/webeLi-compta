-- Une archive reste immuable tant qu’elle existe, mais peut être supprimée
-- explicitement depuis le dossier afin de corriger un archivage en double.

DROP TRIGGER IF EXISTS trg_archives_rapports_immutable_delete;
