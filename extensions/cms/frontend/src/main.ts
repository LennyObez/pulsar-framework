/**
 * CMS frontend entry point.
 *
 * Imports all modules so esbuild can bundle them into a single output file.
 * CSS imports are handled by esbuild's CSS bundler and emitted as a
 * separate `cms-admin.css` file.
 */

// --- Styles ---
import '../styles/cms-admin.css';
import '../styles/cms-admin-dark.css';
import '../styles/cms-public.css';
import '../styles/cms-toast.css';
import '../styles/cms-command-palette.css';
import '../styles/cms-skeleton.css';
import '../styles/cms-page-builder.css';
import '../styles/cms-inline-editor.css';
import '../styles/cms-media-picker.css';
import '../styles/cms-block-inserter.css';
import '../styles/cms-newsletter.css';
import '../styles/cms-comments.css';
import '../styles/cms-lightbox.css';

// --- Shared utilities ---
export { escapeHtml } from './utils/escapeHtml';
export { cmsApi } from './utils/api';

// --- Gallery ---
import './gallery/GalleryComponent';
import './gallery/Lightbox';
import './gallery/ImageCompare';

// --- Forms ---
import './forms/ProofOfWork';

// --- Newsletter ---
import './newsletter/NewsletterSignup';

// --- Comments ---
import './comments/CommentsComponent';
import './comments/CommentForm';

// --- UI components ---
import './ui/ToastContainer';
import './ui/ToastManager';
import './ui/CommandPalette';
import './ui/CommandRegistry';

// --- Theme ---
import './theme/ThemeToggle';

// --- Collaboration ---
import './collaboration/AwarenessOverlay';
import './collaboration/YjsAdapter';

// --- Block editor ---
import './editor/BlockEditor';
import './editor/BlockToolbar';

// --- Page builder ---
import './page-builder/PageBuilder';
import './page-builder/BlockInspector';
import './page-builder/MediaPicker';
import './page-builder/BlockInserter';
import './page-builder/blocks/ParagraphBlock';
import './page-builder/blocks/HeadingBlock';
import './page-builder/blocks/ImageBlock';
import './page-builder/blocks/GalleryBlock';
import './page-builder/blocks/ColumnsBlock';
import './page-builder/blocks/ButtonBlock';
import './page-builder/blocks/VideoBlock';
import './page-builder/blocks/SpacerBlock';
import './page-builder/blocks/SeparatorBlock';
import './page-builder/blocks/HtmlBlock';
import './page-builder/blocks/CodeBlock';
import './page-builder/blocks/AlertBlock';
import './page-builder/blocks/CounterBlock';
import './page-builder/blocks/IconBlock';
import './page-builder/blocks/ProgressBarBlock';
import './page-builder/blocks/QuoteBlock';
import './page-builder/blocks/ListBlock';
import './page-builder/blocks/AudioBlock';
import './page-builder/blocks/EmbedBlock';
import './page-builder/blocks/CtaBlock';
import './page-builder/blocks/HeroBlock';
import './page-builder/blocks/TestimonialBlock';
import './page-builder/blocks/SocialLinksBlock';
import './page-builder/blocks/FileDownloadBlock';
import './page-builder/blocks/MapBlock';
import './page-builder/blocks/PricingTableBlock';
import './page-builder/blocks/TableBlock';
import './page-builder/blocks/AccordionBlock';
import './page-builder/blocks/TabsBlock';
import './page-builder/blocks/CarouselBlock';
import './page-builder/blocks/ButtonGroupBlock';
