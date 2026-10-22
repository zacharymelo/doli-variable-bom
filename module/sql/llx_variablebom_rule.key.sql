-- Copyright (C) 2026 Zach Melo <zach@digitalproperties.works>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

ALTER TABLE llx_variablebom_rule ADD INDEX idx_variablebom_rule_fk_bom (fk_bom);
ALTER TABLE llx_variablebom_rule ADD UNIQUE INDEX uk_variablebom_rule (fk_bomline, rule_type, fk_prod_attr, fk_prod_attr_val);
