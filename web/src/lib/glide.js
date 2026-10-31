// Whether the month grid is gliding to where Today or an arrow sent it.
// Until it arrives, what's on screen is on its way there, so Next steps
// from where it's going: stepped from the month half-way, a quick second
// press aimed back at it, and the grid turned round (#185).

let active = false;

export function glideActive() {
  return active;
}

export function setGlideActive(on) {
  active = !!on;
}
