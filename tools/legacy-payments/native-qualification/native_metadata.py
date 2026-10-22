"""Pure parsing of native action names; never execution or transaction authority."""
from dataclasses import dataclass
import hashlib
import re
import xml.etree.ElementTree as ET

from _schema import AdmissionError, digest, require

MAX_CATALOG = 1048576
MAX_ACTIONS = 8192
ROOT_ATTRIBUTES = frozenset(('func', 'lang', 'binary', 'host', 'themename',
                             'features', 'notify', 'theme'))
NAME = re.compile(r'(?:\*|[A-Za-z0-9_][A-Za-z0-9_.-]{0,191})\Z', re.ASCII)
DECLARATION = re.compile(r'\A\s*<\?xml version=[\'\"]1\.0[\'\"]'
                         r'(?: encoding=[\'\"]UTF-8[\'\"])?\?>', re.ASCII)


@dataclass(frozen=True)
class ActionCatalog:
    """Names and an original-byte fingerprint, with no acceptance flags."""
    actions: frozenset[str]
    sha256: str
    bytes: int

    def __post_init__(self):
        require(type(self.actions) is frozenset and 1 <= len(self.actions) <= MAX_ACTIONS,
                'catalog-actions')
        require(all(type(name) is str and NAME.fullmatch(name) for name in self.actions),
                'catalog-name')
        digest(self.sha256)
        require(type(self.bytes) is int and 1 <= self.bytes <= MAX_CATALOG, 'catalog-size')


def _blank(value):
    return value is None or not value.strip()


def _leaf(node, tag):
    require(node.tag == tag and not node.attrib and not len(node) and _blank(node.tail),
            'catalog-shape')


def _tree(raw):
    require(type(raw) is bytes and 0 < len(raw) <= MAX_CATALOG, 'catalog-size')
    try:
        source = raw.decode('utf-8', errors='strict')
    except UnicodeError:
        raise AdmissionError('catalog-encoding') from None
    require('\x00' not in source and '<!' not in source, 'catalog-xml')
    declaration = DECLARATION.match(source)
    if declaration:
        source = source[declaration.end():]
    require('<?' not in source, 'catalog-xml')
    parser = ET.XMLPullParser(events=('start', 'end'))
    root = None
    depth = elements = 0
    try:
        for offset in range(0, len(source), 4096):
            parser.feed(source[offset:offset + 4096])
            for event, node in parser.read_events():
                if event == 'start':
                    depth += 1
                    elements += 1
                    require(depth <= 3 and elements <= 2 * MAX_ACTIONS + 5, 'catalog-bound')
                    if root is None:
                        root = node
                else:
                    depth -= 1
        parser.close()
    except AdmissionError:
        raise
    except (ET.ParseError, ValueError):
        raise AdmissionError('catalog-xml') from None
    require(root is not None and depth == 0, 'catalog-xml')
    return root


def parse_action_catalog(raw: bytes) -> ActionCatalog:
    """Require original actionlist XML, not CLI prose or a claimed success field.

    Custody and source identity remain separate issuer obligations. A catalog entry
    establishes neither supported invocation parameters nor transaction coupling.
    """
    root = _tree(raw)
    require(root.tag == 'doc' and set(root.attrib) <= ROOT_ATTRIBUTES and
            root.attrib.get('func') == 'actionlist' and _blank(root.text) and
            _blank(root.tail), 'catalog-route')
    require(all(len(value) <= 4096 for value in root.attrib.values()), 'catalog-bound')
    names = set()
    trailers = set()
    for node in root:
        require(not node.attrib and _blank(node.tail), 'catalog-shape')
        if node.tag == 'elem':
            require(not trailers and _blank(node.text) and len(node) == 1, 'catalog-shape')
            action = node[0]
            _leaf(action, 'action')
            name = action.text
            require(type(name) is str and NAME.fullmatch(name), 'catalog-name')
            require(name not in names and len(names) < MAX_ACTIONS, 'catalog-duplicate-or-bound')
            names.add(name)
        else:
            require(node.tag in ('saved_filters', 'tparams') and node.tag not in trailers and
                    _blank(node.text), 'catalog-shape')
            trailers.add(node.tag)
            if node.tag == 'saved_filters':
                require(not len(node), 'catalog-shape')
            else:
                require(len(node) == 2 and tuple(child.tag for child in node) == ('out', 'func'),
                        'catalog-route')
                for child, value in zip(node, ('xml', 'actionlist')):
                    _leaf(child, child.tag)
                    require(child.text == value, 'catalog-route')
    require(trailers == {'saved_filters', 'tparams'}, 'catalog-shape')
    return ActionCatalog(frozenset(names), hashlib.sha256(raw).hexdigest(), len(raw))
