"use strict";(self.webpackChunkgravity_pdf=self.webpackChunkgravity_pdf||[]).push([[815],{7196:(e,t,r)=>{r.r(t),r.d(t,{TemplateSingle:()=>k,default:()=>I});r(9463),r(6280),r(7495),r(1761);var s=r(6540),a=r(5556),i=r.n(a),n=r(9733),o=r(810),p=(r(3792),r(4114),r(2953),r(6347));function l(e,t,r){return(t=function(e){var t=function(e,t){if("object"!=typeof e||!e)return e;var r=e[Symbol.toPrimitive];if(void 0!==r){var s=r.call(e,t||"default");if("object"!=typeof s)return s;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}
/**
 * Renders the template navigation header that get displayed on the
 * /template/:id pages.
 *
 * @package     Gravity PDF
 * @copyright   Copyright (c) 2024, Blue Liquid Designs
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.1
 */(e,"string");return"symbol"==typeof t?t:t+""}(t))in e?Object.defineProperty(e,t,{value:r,enumerable:!0,configurable:!0,writable:!0}):e[t]=r,e}class m extends s.Component{constructor(){super(...arguments),l(this,"handlePreviousTemplate",(e=>{e.preventDefault(),e.stopPropagation();var t=this.props.templates[this.props.templateIndex-1].id;t&&this.props.history.push("/template/"+t)})),l(this,"handleNextTemplate",(e=>{e.preventDefault(),e.stopPropagation();var t=this.props.templates[this.props.templateIndex+1].id;t&&this.props.history.push("/template/"+t)})),l(this,"handleKeyPress",(e=>{this.props.isFirst||37!==e.keyCode||this.handlePreviousTemplate(e),this.props.isLast||39!==e.keyCode||this.handleNextTemplate(e)}))}componentDidMount(){window.addEventListener("keydown",this.handleKeyPress,!1)}componentWillUnmount(){window.removeEventListener("keydown",this.handleKeyPress,!1)}render(){var e=this.props.isFirst,t=this.props.isLast,r=e?"dashicons dashicons-no left disabled":"dashicons dashicons-no left",a=t?"dashicons dashicons-no right disabled":"dashicons dashicons-no right",i=e?"disabled":"",n=t?"disabled":"";return s.createElement("span",null,s.createElement("button",{onClick:this.handlePreviousTemplate,onKeyDown:this.handleKeyPress,className:r,tabIndex:"141",disabled:i},s.createElement("span",{className:"screen-reader-text"},this.props.showPreviousTemplateText)),s.createElement("button",{onClick:this.handleNextTemplate,onKeyDown:this.handleKeyPress,className:a,tabIndex:"141",disabled:n},s.createElement("span",{className:"screen-reader-text"},this.props.showNextTemplateText)))}}l(m,"propTypes",{templates:i().array.isRequired,templateIndex:i().number.isRequired,history:i().object,isFirst:i().bool,isLast:i().bool,showPreviousTemplateText:i().string,showNextTemplateText:i().string});const c=(0,p.y)((0,n.Ng)(((e,t)=>{var r=t.templates,s=t.template.id,a=r.length-1;return{isFirst:r[0].id===s,isLast:r[a].id===s}}))(m));var u=r(4333),h=r(1627);function d(e,t){var r=Object.keys(e);if(Object.getOwnPropertySymbols){var s=Object.getOwnPropertySymbols(e);t&&(s=s.filter((function(t){return Object.getOwnPropertyDescriptor(e,t).enumerable}))),r.push.apply(r,s)}return r}function g(e){for(var t=1;t<arguments.length;t++){var r=null!=arguments[t]?arguments[t]:{};t%2?d(Object(r),!0).forEach((function(t){T(e,t,r[t])})):Object.getOwnPropertyDescriptors?Object.defineProperties(e,Object.getOwnPropertyDescriptors(r)):d(Object(r)).forEach((function(t){Object.defineProperty(e,t,Object.getOwnPropertyDescriptor(r,t))}))}return e}function T(e,t,r){return(t=function(e){var t=function(e,t){if("object"!=typeof e||!e)return e;var r=e[Symbol.toPrimitive];if(void 0!==r){var s=r.call(e,t||"default");if("object"!=typeof s)return s;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}
/**
 * Renders a delete button which then queries our server and
 * removes the selected PDF template
 *
 * @package     Gravity PDF
 * @copyright   Copyright (c) 2024, Blue Liquid Designs
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.1
 */(e,"string");return"symbol"==typeof t?t:t+""}(t))in e?Object.defineProperty(e,t,{value:r,enumerable:!0,configurable:!0,writable:!0}):e[t]=r,e}class f extends s.Component{constructor(){super(...arguments),T(this,"deleteTemplate",(e=>{e.preventDefault(),e.stopPropagation(),window.confirm(this.props.templateConfirmDeleteText)&&(this.props.templateProcessing(this.props.template.id),"success"===this.props.getTemplateProcessing&&this.props.history.push("/template"),this.props.onTemplateDelete(this.props.template.id))})),T(this,"ajaxFailed",(()=>{var e=g(g({},this.props.template),{},{error:this.props.templateDeleteErrorText});this.props.addTemplate(e),this.props.history.push("/template"),this.props.clearTemplateProcessing()}))}componentDidUpdate(){var{getTemplateProcessing:e,history:t}=this.props;"success"===e&&t.push("/template"),"failed"===e&&this.ajaxFailed()}render(){var e=this.props.callbackFunction?this.props.callbackFunction:this.deleteTemplate;return s.createElement("a",{onClick:e,href:"#",tabIndex:"150",className:"button button-secondary delete-theme ed_button","aria-label":this.props.buttonText+" "+GFPDF.template},this.props.buttonText)}}T(f,"propTypes",{template:i().object,addTemplate:i().func,onTemplateDelete:i().func,callbackFunction:i().func,templateProcessing:i().func,clearTemplateProcessing:i().func,getTemplateProcessing:i().string,history:i().object,buttonText:i().string,templateConfirmDeleteText:i().string,templateDeleteErrorText:i().string});const v=(0,p.y)((0,n.Ng)((e=>({getTemplateProcessing:e.template.templateProcessing})),(e=>({addTemplate:t=>{e((0,h.mn)(t))},onTemplateDelete:t=>{e((0,h.I7)(t))},templateProcessing:t=>{e((0,h.We)(t))},clearTemplateProcessing:()=>{e((0,h.v)())}})))(f));function b(e,t,r){return(t=function(e){var t=function(e,t){if("object"!=typeof e||!e)return e;var r=e[Symbol.toPrimitive];if(void 0!==r){var s=r.call(e,t||"default");if("object"!=typeof s)return s;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}
/**
 * Renders the template footer actions that get displayed on the
 * /template/:id pages.
 *
 * @package     Gravity PDF
 * @copyright   Copyright (c) 2024, Blue Liquid Designs
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.1
 */(e,"string");return"symbol"==typeof t?t:t+""}(t))in e?Object.defineProperty(e,t,{value:r,enumerable:!0,configurable:!0,writable:!0}):e[t]=r,e}class x extends s.Component{constructor(){super(...arguments),b(this,"notCoreTemplate",(e=>-1!==e.path.indexOf(this.props.pdfWorkingDirPath)))}render(){var e=this.props.template,t=e.compatible;return s.createElement("div",{className:"theme-actions"},!this.props.isActiveTemplate&&t?s.createElement(u.Ay,{template:e,buttonText:this.props.activateText}):null,!this.props.isActiveTemplate&&this.notCoreTemplate(e)?s.createElement(v,{template:e,ajaxUrl:this.props.ajaxUrl,ajaxNonce:this.props.ajaxNonce,buttonText:this.props.templateDeleteText,templateConfirmDeleteText:this.props.templateConfirmDeleteText,templateDeleteErrorText:this.props.templateDeleteErrorText}):null)}}b(x,"propTypes",{template:i().object.isRequired,isActiveTemplate:i().bool,ajaxUrl:i().string,ajaxNonce:i().string,activateText:i().string,pdfWorkingDirPath:i().string,templateDeleteText:i().string,templateConfirmDeleteText:i().string,templateDeleteErrorText:i().string});const y=x;
/**
 * Display the Template Screenshot for the individual templates (uses different markup - out of our control)
 *
 * @package     Gravity PDF
 * @copyright   Copyright (c) 2024, Blue Liquid Designs
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.1
 */
var P=e=>{var{image:t}=e,r=t?"screenshot":"screenshot blank";return s.createElement("div",{className:"theme-screenshots"},s.createElement("div",{className:r},t?s.createElement("img",{src:t,alt:""}):null))};P.propTypes={image:i().string};const j=P;var D,E,w,N=r(7569),C=r(4368),O=r(9081);
/**
 * Renders a single PDF template, which get displayed on the /template/:id page.
 *
 * @package     Gravity PDF
 * @copyright   Copyright (c) 2024, Blue Liquid Designs
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.1
 */
class k extends s.Component{shouldComponentUpdate(e){return null!=e.template}render(){var e=this.props.template,t=this.props.activeTemplate===e.id;return s.createElement(o.A,{header:s.createElement(c,{template:e,templateIndex:this.props.templateIndex,templates:this.props.templates,showPreviousTemplateText:this.props.showPreviousTemplateText,showNextTemplateText:this.props.showNextTemplateText}),footer:s.createElement(y,{template:e,isActiveTemplate:t,ajaxUrl:this.props.ajaxUrl,ajaxNonce:this.props.ajaxNonce,activateText:this.props.activateText,pdfWorkingDirPath:this.props.pdfWorkingDirPath,templateDeleteText:this.props.templateDeleteText,templateConfirmDeleteText:this.props.templateConfirmDeleteText,templateDeleteErrorText:this.props.templateDeleteErrorText}),closeRoute:"/template"},s.createElement("div",{id:"gfpdf-template-detail-view",className:"gfpdf-template-detail"},s.createElement(j,{image:e.screenshot}),s.createElement("div",{className:"theme-info"},s.createElement(C.oX,{isCurrentTemplate:t,label:this.props.currentTemplateText}),s.createElement(C.SX,{name:e.template,version:e.version,versionLabel:this.props.versionText}),s.createElement(C.UM,{author:e.author,uri:e["author uri"]}),s.createElement(C.YJ,{group:e.group,label:this.props.groupText}),e.long_message?s.createElement(N.A,{text:e.long_message}):null,e.long_error?s.createElement(N.A,{text:e.long_error,error:!0}):null,s.createElement(C.VY,{desc:e.description}),s.createElement(C.YD,{tags:e.tags,label:this.props.tagsText}))))}}D=k,E="propTypes",w={template:i().object,activeTemplate:i().string,templateIndex:i().number,templates:i().array,showPreviousTemplateText:i().string,showNextTemplateText:i().string,ajaxUrl:i().string,ajaxNonce:i().string,activateText:i().string,pdfWorkingDirPath:i().string,templateDeleteText:i().string,templateConfirmDeleteText:i().string,templateDeleteErrorText:i().string,currentTemplateText:i().string,versionText:i().string,groupText:i().string,tagsText:i().string},(E=function(e){var t=function(e,t){if("object"!=typeof e||!e)return e;var r=e[Symbol.toPrimitive];if(void 0!==r){var s=r.call(e,t||"default");if("object"!=typeof s)return s;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}(e,"string");return"symbol"==typeof t?t:t+""}(E))in D?Object.defineProperty(D,E,{value:w,enumerable:!0,configurable:!0,writable:!0}):D[E]=w;const I=(0,n.Ng)(((e,t)=>{var r=(0,O.Ay)(e),s=t.match.params.id,a=e=>e.id===s;return{template:r.find(a),templateIndex:r.findIndex(a),templates:r,activeTemplate:e.template.activeTemplate}}))(k)}}]);