# Design

Stage CMS uses the SKYYWARE identity: navy navigation, a light workspace,
D-DIN type, and a blue action color. The compact S mark works at favicon size.
The writing area uses the same type family as the controls.

| Token | Value |
| --- | --- |
| Canvas | #f6f7f8 |
| Paper | #ffffff |
| Navigation | #010711 |
| Text | #101827 |
| Muted text | #536071 |
| Rule | #d9dee6 |
| Accent | #0c4f91 |
| Soft accent | #edf3f8 |
| Control radius | 6px |
| Sidebar width | 224px |

Fonts are served locally. D-DIN's OFL license ships alongside the font files.
The SVG mark scales without a raster asset or external service.

Pages, Media, and Agents are the primary navigation. Settings holds site-wide
choices. The editor groups a type's fields by purpose. Additional groups can
collapse while their values remain part of the saved revision. Page type,
language, address, summary, and cover sit beside the writing area on desktop
and below it on mobile.

Inputs, textareas, and selects have no CSS outlines. Focus changes their border
and background and adds an inset blue bar. Forced-color mode uses the system
highlight border. Links and buttons retain visible keyboard focus. Never
remove focus visibility to simplify the appearance.

Saving a draft preserves the current publication. Preview shows saved work;
publish makes it public. History restores a prior revision as a new draft.
Changing type keeps incompatible content visible until the editor moves or
clears it. Save errors retain the submitted text and revision number.

Use direct labels and state what an action changes. Status needs text as well
as color. Forms work without JavaScript; enhancements handle formatting,
keyboard saving, word count, and unsaved changes. Respect reduced motion.
