import{c,m as i,r as d,j as e}from"./chunks/mount-DDHaFljI.js";/**
 * @license lucide-react v0.469.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const p=c("ChevronDown",[["path",{d:"m6 9 6 6 6-6",key:"qrunsl"}]]);function l({faqs:a=[]}){const[r,o]=d.useState(a.length>0?0:-1);return e.jsx("div",{className:"up-faq-list",children:a.map((t,s)=>{const n=s===r;return e.jsxs("div",{className:"up-faq-item"+(n?" open":""),children:[e.jsxs("button",{type:"button",className:"up-faq-question","aria-expanded":n,onClick:()=>o(n?-1:s),children:[e.jsx("span",{children:t.q}),e.jsx("span",{className:"up-faq-caret",style:{transform:n?"rotate(180deg)":"none",transition:"transform .2s ease"},children:e.jsx(p,{size:14,"aria-hidden":"true"})})]}),n&&e.jsx("div",{className:"up-faq-answer",children:t.a})]},s)})})}i("react-faq",l);

