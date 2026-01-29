-- Adicionar campo de permissões na tabela users
ALTER TABLE users ADD COLUMN permissions JSON DEFAULT NULL;

-- Atualizar usuários existentes com permissões padrão baseadas no admin_level
UPDATE users SET permissions = '{"view_dashboard":true,"view_assigned":true,"validate":true,"view_ranking":true,"view_wiki":true}' 
WHERE admin_level = 'convidado';

UPDATE users SET permissions = '{"view_dashboard":true,"create_validation":true,"view_assigned":true,"view_all_validations":true,"validate":true,"view_ranking":true,"view_wiki":true}' 
WHERE admin_level = 'user';

UPDATE users SET permissions = '{"view_dashboard":true,"create_validation":true,"view_assigned":true,"view_all_validations":true,"validate":true,"view_ranking":true,"view_reports":true,"manage_packages":true,"manage_users":true,"view_wiki":true}' 
WHERE admin_level = 'admin_principal';
