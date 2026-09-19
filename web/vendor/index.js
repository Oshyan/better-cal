// Aggregator for the vendored UI runtime. Everything imports from here so
// swapping or upgrading vendor files touches one place.
import { h, render, Component, Fragment, createContext, createRef, cloneElement } from './preact.module.js';
import {
  useState, useReducer, useEffect, useLayoutEffect, useRef, useMemo,
  useCallback, useContext, useImperativeHandle,
} from './hooks.module.js';
import htm from './htm.module.js';

const html = htm.bind(h);

export {
  h, render, Component, Fragment, createContext, createRef, cloneElement,
  useState, useReducer, useEffect, useLayoutEffect, useRef, useMemo,
  useCallback, useContext, useImperativeHandle,
  html,
};
