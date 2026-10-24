-- Справочные данные (обязательные для работы системы)

INSERT INTO roles (code, name) VALUES
  ('client', 'Клиент'),
  ('consultant', 'Консультант'),
  ('developer', 'Разработчик'),
  ('support', 'Специалист ТП'),
  ('manager', 'Менеджер'),
  ('tech_director', 'Технический директор'),
  ('admin', 'Администратор');

INSERT INTO appointment_statuses (code, name, blocks_slot, sort_order) VALUES
  ('pending_payment', 'Ожидает оплаты', 1, 1),
  ('confirmed', 'Подтверждена', 1, 2),
  ('completed', 'Проведена', 1, 3),
  ('no_show', 'Неявка', 0, 4),
  ('cancelled', 'Отменена', 0, 5);

INSERT INTO priorities (code, name, level, weight, response_minutes, resolve_hours, calendar_time) VALUES
  ('low', 'Низкий', 1, 1, 480, 120, 0),
  ('medium', 'Средний', 2, 2, 240, 72, 0),
  ('high', 'Высокий', 3, 3, 60, 24, 1),
  ('critical', 'Критический', 4, 5, 15, 8, 1);

INSERT INTO ticket_categories (name) VALUES
  ('Доступ и учётные записи'),
  ('Ошибка в ПО'),
  ('Сбой'),
  ('Настройка и обновление'),
  ('Консультация'),
  ('Запрос на доработку');

INSERT INTO settings (name, value, description) VALUES
  ('payment_timeout_min', '30', 'Время удержания слота до оплаты, мин'),
  ('slot_step_min', '30', 'Шаг сетки слотов, мин'),
  ('buffer_min', '10', 'Технический перерыв между консультациями, мин'),
  ('min_booking_hours', '2', 'Минимум часов до начала для записи'),
  ('booking_horizon_days', '60', 'Горизонт записи, дней'),
  ('free_cancel_hours', '24', 'Порог полного возврата, ч'),
  ('partial_refund_percent', '50', 'Процент возврата при отмене за 2–24 ч'),
  ('no_refund_hours', '2', 'Менее скольких часов возврата нет'),
  ('reminder_hours', '24,1', 'За сколько часов напоминать о консультации'),
  ('auto_assign', '1', 'Автоназначение заявок по нагрузке (1/0)'),
  ('overdue_check_min', '5', 'Период проверки SLA, мин'),
  ('task_due_notify_hours', '24', 'За сколько часов уведомлять о сроке задачи'),
  ('sla_warn_percent', '25', 'Порог предупреждения SLA, % оставшегося времени'),
  ('sla_target_percent', '92', 'Целевой уровень SLA, %'),
  ('business_start', '09:00', 'Начало рабочего дня'),
  ('business_end', '18:00', 'Конец рабочего дня'),
  ('company_name', 'ООО «Трайтэк»', 'Название компании'),
  ('support_email', 'support@tritech-service.ru', 'Адрес техподдержки');
