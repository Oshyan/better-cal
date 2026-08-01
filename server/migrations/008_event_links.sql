-- Trips (GH #2): an event with is_container=1 groups other events via
-- event_links. A link is a relationship, not ownership — members keep their
-- own calendar, times and lifecycle. Deleting a container hard-row cascades
-- only the links (members survive); the app's soft delete leaves link rows in
-- place and reads filter on the container's deleted_at instead.
SET NAMES utf8mb4;

ALTER TABLE events ADD COLUMN is_container TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE event_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  container_id BIGINT UNSIGNED NOT NULL,   -- events.id with is_container=1
  event_id BIGINT UNSIGNED NOT NULL,       -- the attached event
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_link (container_id, event_id),
  CONSTRAINT fk_link_container FOREIGN KEY (container_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_link_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;
