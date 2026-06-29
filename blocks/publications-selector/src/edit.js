import { __ } from '@wordpress/i18n';
import {
    useBlockProps,
    InspectorControls
} from '@wordpress/block-editor';
import {
    PanelBody,
    SelectControl,
    TextControl,
    Button,
    Spinner,
    Notice
} from '@wordpress/components';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Hook personalizado de debounce
 * Implementación propia para evitar dependencia de @wordpress/compose
 */
function useDebounce(value, delay) {
    const [debouncedValue, setDebouncedValue] = useState(value);

    useEffect(() => {
        const handler = setTimeout(() => {
            setDebouncedValue(value);
        }, delay);

        return () => {
            clearTimeout(handler);
        };
    }, [value, delay]);

    return debouncedValue;
}

export default function Edit({ attributes, setAttributes }) {
    const { selectedPublicationIds } = attributes;

    // Estado local para el editor (no se guarda en el bloque)
    const [members, setMembers] = useState([]);
    const [selectedMemberId, setSelectedMemberId] = useState(0);
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState([]);
    const [selectedPublications, setSelectedPublications] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');
    const requestIdRef = useRef(0);

    const debouncedSearch = useDebounce(searchQuery, 500);

    // Cargar miembros al montar el componente
    useEffect(() => {
        apiFetch({ path: '/openalex/v1/members' })
            .then(setMembers)
            .catch(err => setError('Error al cargar miembros: ' + err.message));
    }, []);

    // Buscar publicaciones cuando cambia la búsqueda
    
    useEffect(() => {
        if (!selectedMemberId || !debouncedSearch || debouncedSearch.length < 2) {
            setSearchResults([]);
            return;
        }

        setIsLoading(true);
        setError('');

        apiFetch({
            path: `/openalex/v1/publications/${selectedMemberId}?search=${encodeURIComponent(debouncedSearch)}`
        })
            .then(results => {
                setSearchResults(results);
                setIsLoading(false);
            })
            .catch(err => {
                setError('Error al buscar: ' + err.message);
                setIsLoading(false);
            });
    }, [selectedMemberId, debouncedSearch]);

// Buscar publicaciones cuando cambia la búsqueda
    const addPublication = (pub) => {
        if (!selectedPublicationIds.includes(pub.pub_id)) {
            const newIds = [...selectedPublicationIds, pub.pub_id];
            setAttributes({
                selectedPublicationIds: newIds
            });
            setSelectedPublications(prev => [...prev, { ...pub, pub_id: pub.pub_id }]);
        }
    };

    const removePublication = (pubId) => {
        const newIds = selectedPublicationIds.filter(id => id !== pubId);
        setAttributes({
            selectedPublicationIds: newIds
        });
        setSelectedPublications(prev => prev.filter(pub => pub.pub_id !== pubId));
    };

    useEffect(() => {
        const ids = (selectedPublicationIds || []).filter(Boolean);
        const requestId = ++requestIdRef.current;

        if (!ids.length) {
            setSelectedPublications([]);
            return;
        }

        const path = `/openalex/v1/publications-by-ids?ids=${encodeURIComponent(ids.join(','))}`;
        apiFetch({ path })
            .then(results => {
                if (requestId !== requestIdRef.current) {
                    return;
                }
                setSelectedPublications(results || []);
            })
            .catch(() => {
                if (requestId === requestIdRef.current) {
                    setSelectedPublications([]);
                }
            });
    }, [selectedPublicationIds]);

    // Invalidate server-side cached HTML when selected IDs change
    useEffect(() => {
        // run only in editor (user is authenticated) and when selection is present
        if (!selectedPublicationIds || selectedPublicationIds.length === 0) return;

        // fire-and-forget POST to clear cache for these IDs
        apiFetch({
            path: '/openalex/v1/publications-cache/clear',
            method: 'POST',
            data: { ids: selectedPublicationIds }
        }).catch(() => {
            // don't surface editor errors; cache invalidation is best-effort
        });
    }, [selectedPublicationIds]);

    const blockProps = useBlockProps({
        className: 'openalex-publications-selector-block'
    });

    return (
        <>
            <div {...blockProps}>
                <h3>{__('Publicaciones OpenAlex Seleccionadas', 'openalex-team')}</h3>

                <SelectControl
                    label={__('Miembro del equipo (para buscar)', 'openalex-team')}
                    value={selectedMemberId}
                    options={[
                        { label: __('Seleccionar miembro...', 'openalex-team'), value: 0 },
                        ...members.map(m => ({
                            label: m.title,
                            value: m.id
                        }))
                    ]}
                    onChange={(value) => setSelectedMemberId(parseInt(value))}
                    help={__('Selecciona un miembro para buscar sus publicaciones. Puedes cambiar de miembro después sin perder las publicaciones ya seleccionadas.', 'openalex-team')}
                />

                {!selectedMemberId && (
                    <Notice status="info" isDismissible={false}>
                        {__('Selecciona un miembro del equipo arriba para comenzar a buscar publicaciones.', 'openalex-team')}
                    </Notice>
                )}

                {selectedMemberId > 0 && (
                    <>
                        <TextControl
                            label={__('Buscar publicaciones por título', 'openalex-team')}
                            value={searchQuery}
                            onChange={setSearchQuery}
                            placeholder={__('Escribe al menos 2 caracteres...', 'openalex-team')}
                        />

                        {isLoading && <Spinner />}

                        {error && (
                            <Notice status="error" isDismissible={false}>
                                {error}
                            </Notice>
                        )}

                        {searchResults.length > 0 && (
                            <div className="search-results">
                                <h4>{__('Resultados de búsqueda', 'openalex-team')}</h4>
                                <ul>
                                    {searchResults.map(pub => (
                                        <li key={pub.pub_id}>
                                            <div className="pub-info">
                                                <strong>{pub.title}</strong>
                                                <span className="pub-meta">
                                                    ({pub.year}) - {pub.type}
                                                </span>
                                                {pub.author && (
                                                    <span className="pub-authors" style={{ display: 'block', color: '#666', fontSize: '0.9em' }}>
                                                        {pub.author}
                                                    </span>
                                                )}
                                            </div>
                                            <Button
                                                isPrimary
                                                onClick={() => addPublication(pub)}
                                                disabled={selectedPublicationIds.includes(pub.pub_id)}
                                            >
                                                {selectedPublicationIds.includes(pub.pub_id)
                                                    ? __('Agregada', 'openalex-team')
                                                    : __('Agregar', 'openalex-team')}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {debouncedSearch && debouncedSearch.length >= 2 && searchResults.length === 0 && !isLoading && (
                            <p style={{color: '#666', fontStyle: 'italic'}}>
                                {__('No se encontraron publicaciones con ese título.', 'openalex-team')}
                            </p> 
                        )}

                        {selectedPublicationIds.length > 0 && (
                            <div className="selected-publications">
                                <h4>
                                    {__('Publicaciones seleccionadas', 'openalex-team')}
                                    ({selectedPublicationIds.length})
                                </h4>
                                <p className="components-base-control__help">
                                    {__('Estas publicaciones se mostrarán en el frontend al guardar la página.', 'openalex-team')}
                                </p>
                                <ul>
                                    {selectedPublications.map(pub => (
                                        <li key={pub.pub_id}>
                                            <div className="pub-info">
                                                <strong>{pub.title || `ID: ${pub.pub_id}`}</strong>
                                                {pub.author && (
                                                    <span className="pub-authors" style={{ display: 'block', color: '#666', fontSize: '0.9em' }}>
                                                        {pub.author}
                                                    </span>
                                                )}
                                            </div>
                                            <Button
                                                isDestructive
                                                isSmall
                                                onClick={() => removePublication(pub.pub_id)}
                                            >
                                                {__('Eliminar', 'openalex-team')}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}