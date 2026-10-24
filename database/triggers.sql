-- Триггеры: запрет пересечения активных записей одного консультанта.
-- Статементы разделены строкой "--#".

CREATE TRIGGER trg_app_no_overlap_ins BEFORE INSERT ON appointments FOR EACH ROW
BEGIN
  IF (SELECT blocks_slot FROM appointment_statuses WHERE id = NEW.status_id) = 1
     AND EXISTS (
       SELECT 1 FROM appointments a
       JOIN appointment_statuses s ON s.id = a.status_id
       WHERE a.consultant_id = NEW.consultant_id AND s.blocks_slot = 1
         AND a.start_at < NEW.end_at AND a.end_at > NEW.start_at
     ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Время консультанта уже занято';
  END IF;
END
--#
CREATE TRIGGER trg_app_no_overlap_upd BEFORE UPDATE ON appointments FOR EACH ROW
BEGIN
  IF (SELECT blocks_slot FROM appointment_statuses WHERE id = NEW.status_id) = 1
     AND (NEW.start_at <> OLD.start_at OR NEW.end_at <> OLD.end_at OR NEW.status_id <> OLD.status_id)
     AND EXISTS (
       SELECT 1 FROM appointments a
       JOIN appointment_statuses s ON s.id = a.status_id
       WHERE a.id <> NEW.id AND a.consultant_id = NEW.consultant_id AND s.blocks_slot = 1
         AND a.start_at < NEW.end_at AND a.end_at > NEW.start_at
     ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Время консультанта уже занято';
  END IF;
END
